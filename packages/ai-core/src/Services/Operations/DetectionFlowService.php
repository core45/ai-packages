<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Core45\AiCore\Jobs\AiOperations\PollAiDetectionJob;
use Core45\AiCore\Models\AiDetection;
use Core45\AiCore\Models\Concerns\HasAiDetection;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\AiOperations\ClientReference;
use Core45\AiCore\DTOs\DetectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectionFlowService
{
    use ConvertsThrowableToErrorCode;

    /**
     * Submit local text to phasea and schedule resumable polling.
     */
    public function submit(
        Model $model,
        string $locale,
        string $operationId,
        ?HumanizeContinuation $continuation = null,
        ?QueuedChainContinuation $workflowContinuation = null,
    ): ?AiDetection {
        if (!(bool) config('ai.assistant.ai_detection.enabled', false)) {
            return null;
        }

        $persistedModel = $model->newQuery()->find($model->getKey());

        if (!$persistedModel instanceof Model) {
            return null;
        }

        $detectableText = [$persistedModel, 'getAiDetectableText'];

        if (!is_callable($detectableText)) {
            return null;
        }

        $text = $detectableText($locale);

        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        $wordCount = HasAiDetection::countWords($text);

        if ($wordCount < (int) config('ai.assistant.ai_detection.min_words', 50)) {
            return null;
        }

        $requestHash = hash('sha256', $text);
        $activeIntentKey = hash('sha256', implode("\0", [
            'detect',
            $persistedModel::class,
            (string) $persistedModel->getKey(),
            $locale,
            $requestHash,
        ]));
        $humanizeContinuationPayload = $continuation?->encode();
        $continuationService = app(AiOperationContinuationService::class);

        $existingDetection = AiDetection::query()
            ->where('operation_id', $operationId)
            ->orWhere('active_intent_key', $activeIntentKey)
            ->first();

        if ($existingDetection instanceof AiDetection) {
            if ($existingDetection->humanize_continuation_payload === null && $humanizeContinuationPayload !== null) {
                $existingDetection->update(['humanize_continuation_payload' => $humanizeContinuationPayload]);
            }

            $continuationService->attach($existingDetection, $workflowContinuation);

            if ($existingDetection->isPending() && $existingDetection->submission_state === 'accepted') {
                $this->schedulePoll($existingDetection->id);
            } elseif (!$existingDetection->isPending()) {
                app(DetectionPollService::class)->poll($existingDetection->id);
            }

            return $existingDetection;
        }

        try {
            $detection = AiDetection::create([
                'detectable_type' => $persistedModel::class,
                'detectable_id' => $persistedModel->getKey(),
                'locale' => $locale,
                'word_count' => $wordCount,
                'status' => 'pending',
                'operation_id' => $operationId,
                'request_text' => $text,
                'request_hash' => $requestHash,
                'active_intent_key' => $activeIntentKey,
                'submission_state' => 'creating',
                'humanize_continuation_payload' => $humanizeContinuationPayload,
                'poll_give_up_at' => now()->addSeconds(
                    (int) config('ai.assistant.ai_detection.poll_give_up_seconds', 150),
                ),
            ]);
        } catch (QueryException $exception) {
            $detection = AiDetection::query()
                ->where('active_intent_key', $activeIntentKey)
                ->first();

            if (!$detection instanceof AiDetection) {
                throw $exception;
            }

            $continuationService->attach($detection, $workflowContinuation);

            if ($detection->submission_state === 'accepted') {
                $this->schedulePoll($detection->id);
            }

            return $detection;
        }

        $continuationService->attach($detection, $workflowContinuation);

        $detection->update([
            'client_reference' => ClientReference::forDetection($detection->id),
        ]);

        return $this->resumeSubmission($detection->id);
    }

    public function resumeSubmission(int $detectionId): ?AiDetection
    {
        $detection = AiDetection::find($detectionId);

        if (!$detection instanceof AiDetection
            || !$detection->isPending()
            || $detection->submission_state !== 'creating') {
            return $detection;
        }

        if (!is_string($detection->request_text) || trim($detection->request_text) === '') {
            return $this->failSubmission($detection, 'invalid_response');
        }

        $modelType = $detection->detectable_type;

        if (!is_subclass_of($modelType, Model::class)) {
            return $this->failSubmission($detection, 'invalid_response');
        }

        if (!is_string($detection->client_reference) || $detection->client_reference === '') {
            $detection->update([
                'client_reference' => ClientReference::forDetection($detection->id),
            ]);
        }

        try {
            $result = app(RemoteAiClient::class)->detect(new DetectionRequest(
                modelType: $modelType,
                modelId: $detection->detectable_id,
                locale: $detection->locale,
                text: $detection->request_text,
                operationId: (string) $detection->operation_id,
                clientReference: $detection->client_reference,
            ));
        } catch (Throwable $throwable) {
            $errorCode = $this->errorCodeForThrowable($throwable);

            Log::error('AI detection submission failed', [
                'model' => $detection->detectable_type,
                'model_id' => $detection->detectable_id,
                'locale' => $detection->locale,
                'error_code' => $errorCode,
                'exception_class' => $throwable::class,
            ]);

            return $this->failSubmission($detection, $errorCode);
        }

        if ($result === null
            || !in_array($result->status, ['queued', 'processing', 'done'], true)
            || $result->operationId !== $detection->operation_id) {
            $errorCode = $result->errorCode ?? 'invalid_response';

            Log::error('AI detection submission returned a terminal failure', [
                'model' => $detection->detectable_type,
                'model_id' => $detection->detectable_id,
                'locale' => $detection->locale,
                'operation_id' => $detection->operation_id,
                'error_code' => $errorCode,
            ]);

            return $this->failSubmission($detection, $errorCode, $result?->status);
        }

        $detection->update([
            'submission_state' => 'accepted',
            'phasea_status' => $result->status,
            'error_code' => null,
        ]);

        // Same-operation_id replay statuses (audit D4): 'processing' means
        // phasea already accepted this operation on a previous submission —
        // polling continues as for 'queued'. 'done' means phasea completed the
        // work (and spent budget) while we were crash-recovering — run the
        // normal poll terminal path immediately (it re-reads the operation and
        // reuses the existing completion/write-back logic) instead of failing
        // the row as 'invalid_response'.
        if ($result->status === 'done') {
            app(DetectionPollService::class)->poll($detection->id);
        } else {
            $this->schedulePoll($detection->id);
        }

        return $detection->refresh();
    }

    private function failSubmission(
        AiDetection $detection,
        string $errorCode,
        ?string $phaseaStatus = null,
    ): AiDetection {
        $detection->update([
            'status' => 'failed',
            'submission_state' => 'terminal',
            'phasea_status' => $phaseaStatus,
            'error_code' => $errorCode,
            'active_intent_key' => null,
        ]);

        app(AiOperationContinuationService::class)->release($detection->refresh());

        return $detection->refresh();
    }

    private function schedulePoll(int $detectionId): void
    {
        PollAiDetectionJob::dispatch($detectionId)
            ->onQueue((string) config('ai.assistant.ai_detection.queue', 'ai-bulk'))
            ->delay(now()->addSeconds(
                (int) config('ai.assistant.ai_detection.poll_interval_seconds', 2),
            ));
    }
}
