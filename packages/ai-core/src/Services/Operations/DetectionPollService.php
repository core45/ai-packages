<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Core45\AiCore\Jobs\AiOperations\PollAiDetectionJob;
use Core45\AiCore\Jobs\AiOperations\PollAiHumanizationJob;
use Core45\AiCore\Models\AiDetection;
use Core45\AiCore\Models\AiHumanization;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\AiOperations\ClientReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectionPollService
{
    use ConvertsThrowableToErrorCode;

    private const AI_SCORE_THRESHOLD = 50.0;

    public function poll(int $detectionId): void
    {
        $detection = AiDetection::find($detectionId);

        if (!$detection instanceof AiDetection) {
            Log::warning('AI detection poll row was not found', [
                'detection_id' => $detectionId,
            ]);

            return;
        }

        $continuation = HumanizeContinuation::decode($detection->humanize_continuation_payload);

        if ($detection->isDone()) {
            $this->resumeHumanization($detection, $continuation);

            return;
        }

        if ($detection->isFailed()) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        if ($detection->submission_state !== 'accepted') {
            return;
        }

        if ($detection->poll_give_up_at?->isPast() === true) {
            $detection->update([
                'status' => 'failed',
                'error_code' => 'poll_give_up',
                'active_intent_key' => null,
            ]);
            app(AiOperationContinuationService::class)->release($detection->refresh());

            return;
        }

        try {
            $body = app(RemoteAiClient::class)->pollOperation(
                'detect',
                (string) $detection->operation_id,
            );
        } catch (Throwable $throwable) {
            $errorCode = $this->errorCodeForThrowable($throwable);

            $detection->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'active_intent_key' => null,
            ]);
            app(AiOperationContinuationService::class)->release($detection->refresh());

            Log::error('AI detection poll failed', [
                'detection_id' => $detection->id,
                'model' => $detection->detectable_type,
                'model_id' => $detection->detectable_id,
                'locale' => $detection->locale,
                'error_code' => $errorCode,
                'exception_class' => $throwable::class,
            ]);

            return;
        }

        if (!$this->hasValidCorrelation($body, $detection)) {
            $this->failInvalidResponse($detection);

            return;
        }

        $status = $body['status'] ?? null;

        if (!is_string($status)) {
            $this->failInvalidResponse($detection);

            return;
        }

        if (in_array($status, ['queued', 'processing'], true)) {
            $detection->update(['phasea_status' => $status]);
            $this->schedulePoll($detection->id);

            return;
        }

        if ($status === 'done') {
            $score = data_get($body, 'result.score');
            $resultDetails = data_get($body, 'result.result_details');

            if (!is_numeric($score) || !is_array($resultDetails)) {
                $detection->update([
                    'status' => 'failed',
                    'phasea_status' => 'done',
                    'error_code' => 'result_expired',
                    'active_intent_key' => null,
                ]);
                app(AiOperationContinuationService::class)->release($detection->refresh());

                Log::error('AI detection result expired before phaseb consumed it', [
                    'detection_id' => $detection->id,
                    'operation_id' => $detection->operation_id,
                    'error_code' => 'result_expired',
                ]);

                return;
            }

            $detection->update([
                'status' => 'done',
                'phasea_status' => 'done',
                'score' => (float) $score,
                'result_details' => $resultDetails,
                'external_id' => $this->vendorDocumentId($body),
            ]);

            $this->handleHumanizationGate($detection->refresh(), $continuation);

            return;
        }

        if (in_array($status, ['failed', 'submission_outcome_unknown'], true)) {
            $errorCode = data_get($body, 'failure.error_code', 'unknown');
            $safeErrorCode = is_string($errorCode) ? $errorCode : 'unknown';

            $detection->update([
                'status' => 'failed',
                'phasea_status' => $status,
                'error_code' => $safeErrorCode,
                'external_id' => $this->vendorDocumentId($body),
                'active_intent_key' => null,
            ]);

            app(AiOperationContinuationService::class)->release($detection->refresh());

            if ($status === 'submission_outcome_unknown') {
                Log::error('AI detection submission_outcome_unknown; never auto-resubmitted', [
                    'detection_id' => $detection->id,
                    'operation_id' => $detection->operation_id,
                    'error_code' => $safeErrorCode,
                ]);
            }

            return;
        }

        $this->failInvalidResponse($detection);
    }

    private function handleHumanizationGate(
        AiDetection $detection,
        ?HumanizeContinuation $continuation,
    ): void {
        if ($detection->score === null || $detection->score < self::AI_SCORE_THRESHOLD) {
            $detection->update(['active_intent_key' => null]);

            if ($detection->score !== null) {
                Log::info('DetectAndHumanizeJob: Content appears human, skipping humanization', [
                    'model' => $detection->detectable_type,
                    'model_id' => $detection->detectable_id,
                    'locale' => $detection->locale,
                    'score' => $detection->score,
                ]);
            }

            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        if ($continuation === null || !(bool) config('ai.assistant.ai_humanization.enabled', false)) {
            $detection->update(['active_intent_key' => null]);
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $this->fireHumanization($detection, $continuation);
    }

    private function resumeHumanization(
        AiDetection $detection,
        ?HumanizeContinuation $continuation,
    ): void {
        if ($continuation === null
            || !(bool) config('ai.assistant.ai_humanization.enabled', false)
            || $detection->score === null
            || $detection->score < self::AI_SCORE_THRESHOLD) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $humanization = AiHumanization::query()
            ->where('operation_id', $continuation->operationId)
            ->first();

        if (!$humanization instanceof AiHumanization) {
            $this->fireHumanization($detection, $continuation);

            return;
        }

        if ($humanization->isPending()) {
            PollAiHumanizationJob::dispatch($humanization->id)
                ->onQueue((string) config('ai.assistant.ai_humanization.queue', 'ai-bulk'))
                ->delay(now()->addSeconds(
                    (int) config('ai.assistant.ai_humanization.poll_interval_seconds', 5),
                ));

            app(AiOperationContinuationService::class)->transfer($detection, $humanization);

            return;
        }

        app(AiOperationContinuationService::class)->transfer($detection, $humanization);
        app(AiOperationContinuationService::class)->release($humanization);
    }

    private function fireHumanization(
        AiDetection $detection,
        HumanizeContinuation $continuation,
    ): void {
        $modelClass = $continuation->modelType;

        if (!is_subclass_of($modelClass, Model::class)) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $model = $modelClass::query()->find($continuation->modelId);

        if (!$model instanceof Model || !$model instanceof TranslatableContract) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $translation = $model->translate($continuation->locale);

        if (!$translation instanceof Model) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $rawText = (string) ($translation->{$continuation->field} ?? '');

        if (trim(strip_tags($rawText)) === '') {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        $humanization = app(HumanizationFlowService::class)->submit(
            $model,
            $continuation->locale,
            $continuation->field,
            $rawText,
            $continuation->options,
            $continuation->operationId,
        );

        if (!$humanization instanceof AiHumanization) {
            app(AiOperationContinuationService::class)->release($detection);

            return;
        }

        app(AiOperationContinuationService::class)->transfer($detection, $humanization);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function vendorDocumentId(array $body): ?string
    {
        $vendorDocumentId = $body['vendor_document_id'] ?? null;

        return is_string($vendorDocumentId) && $vendorDocumentId !== ''
            ? $vendorDocumentId
            : null;
    }

    private function schedulePoll(int $detectionId): void
    {
        PollAiDetectionJob::dispatch($detectionId)
            ->onQueue((string) config('ai.assistant.ai_detection.queue', 'ai-bulk'))
            ->delay(now()->addSeconds(
                (int) config('ai.assistant.ai_detection.poll_interval_seconds', 2),
            ));
    }

    private function failInvalidResponse(AiDetection $detection): void
    {
        $detection->update([
            'status' => 'failed',
            'error_code' => 'invalid_response',
            'active_intent_key' => null,
        ]);

        app(AiOperationContinuationService::class)->release($detection->refresh());

        Log::error('AI detection poll returned an invalid status', [
            'detection_id' => $detection->id,
            'operation_id' => $detection->operation_id,
            'error_code' => 'invalid_response',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hasValidCorrelation(array $body, AiDetection $detection): bool
    {
        $operationId = $body['operation_id'] ?? null;
        $surface = $body['surface'] ?? null;
        $clientReference = $body['client_reference'] ?? null;

        return is_string($operationId)
            && hash_equals((string) $detection->operation_id, $operationId)
            && $surface === 'detect'
            && is_string($clientReference)
            && ClientReference::isValid($clientReference)
            && hash_equals((string) $detection->client_reference, $clientReference);
    }
}
