<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Core45\AiCore\Jobs\AiOperations\PollAiHumanizationJob;
use Core45\AiCore\Models\AiHumanization;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\AiOperations\ClientReference;
use Core45\AiCore\DTOs\HumanizationRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class HumanizationFlowService
{
    use ConvertsThrowableToErrorCode;

    /**
     * Submit local text to phasea and schedule resumable polling.
     *
     * @param  array{readability?: string, purpose?: string, strength?: string}  $options
     */
    public function submit(
        Model $model,
        string $locale,
        string $field,
        string $text,
        array $options,
        string $operationId,
        ?QueuedChainContinuation $workflowContinuation = null,
    ): ?AiHumanization {
        if (!(bool) config('ai.assistant.ai_humanization.enabled', false)) {
            return null;
        }

        $persistedModel = $model->newQuery()->find($model->getKey());

        if (!$persistedModel instanceof Model) {
            return null;
        }

        if (trim(strip_tags($text)) === '') {
            return null;
        }

        $resolvedOptions = [
            'readability' => $options['readability'] ?? (string) config('ai.assistant.ai_humanization.readability', 'University'),
            'purpose' => $options['purpose'] ?? (string) config('ai.assistant.ai_humanization.purpose', 'Article'),
            'strength' => $options['strength'] ?? (string) config('ai.assistant.ai_humanization.strength', 'Balanced'),
        ];
        $requestHash = hash('sha256', $text."\0".json_encode($resolvedOptions, JSON_THROW_ON_ERROR));
        $activeIntentKey = hash('sha256', implode("\0", [
            'humanize',
            $persistedModel::class,
            (string) $persistedModel->getKey(),
            $locale,
            $field,
            $requestHash,
        ]));
        $continuationService = app(AiOperationContinuationService::class);

        $existingHumanization = AiHumanization::query()
            ->where('operation_id', $operationId)
            ->orWhere('active_intent_key', $activeIntentKey)
            ->first();

        if ($existingHumanization instanceof AiHumanization) {
            $continuationService->attach($existingHumanization, $workflowContinuation);

            if ($existingHumanization->isPending() && $existingHumanization->submission_state === 'accepted') {
                $this->schedulePoll($existingHumanization->id);
            } elseif (!$existingHumanization->isPending()) {
                app(HumanizationPollService::class)->poll($existingHumanization->id);
            }

            return $existingHumanization;
        }

        try {
            $humanization = AiHumanization::create([
                'humanizable_type' => $persistedModel::class,
                'humanizable_id' => $persistedModel->getKey(),
                'locale' => $locale,
                'field' => $field,
                'original_text' => $text,
                'status' => 'pending',
                'operation_id' => $operationId,
                'request_hash' => $requestHash,
                'active_intent_key' => $activeIntentKey,
                'submission_state' => 'creating',
                'resolved_options' => $resolvedOptions,
                'poll_give_up_at' => now()->addSeconds(
                    (int) config('ai.assistant.ai_humanization.poll_give_up_seconds', 240),
                ),
            ]);
        } catch (QueryException $exception) {
            $humanization = AiHumanization::query()
                ->where('active_intent_key', $activeIntentKey)
                ->first();

            if (!$humanization instanceof AiHumanization) {
                throw $exception;
            }

            $continuationService->attach($humanization, $workflowContinuation);

            if ($humanization->submission_state === 'accepted') {
                $this->schedulePoll($humanization->id);
            }

            return $humanization;
        }

        $continuationService->attach($humanization, $workflowContinuation);

        $humanization->update([
            'client_reference' => ClientReference::forHumanization($humanization->id),
        ]);

        return $this->resumeSubmission($humanization->id);
    }

    public function resumeSubmission(int $humanizationId): ?AiHumanization
    {
        $humanization = AiHumanization::find($humanizationId);

        if (!$humanization instanceof AiHumanization
            || !$humanization->isPending()
            || $humanization->submission_state !== 'creating') {
            return $humanization;
        }

        if (!is_array($humanization->resolved_options)) {
            return $this->failSubmission($humanization, 'invalid_response');
        }

        $modelType = $humanization->humanizable_type;

        if (!is_subclass_of($modelType, Model::class)) {
            return $this->failSubmission($humanization, 'invalid_response');
        }

        if (!is_string($humanization->client_reference) || $humanization->client_reference === '') {
            $humanization->update([
                'client_reference' => ClientReference::forHumanization($humanization->id),
            ]);
        }

        try {
            $result = app(RemoteAiClient::class)->humanize(new HumanizationRequest(
                modelType: $modelType,
                modelId: $humanization->humanizable_id,
                locale: $humanization->locale,
                field: $humanization->field,
                text: $humanization->original_text,
                options: $humanization->resolved_options,
                operationId: (string) $humanization->operation_id,
                clientReference: $humanization->client_reference,
            ));
        } catch (Throwable $throwable) {
            $errorCode = $this->errorCodeForThrowable($throwable);

            Log::error('AI humanization submission failed', [
                'model' => $humanization->humanizable_type,
                'model_id' => $humanization->humanizable_id,
                'locale' => $humanization->locale,
                'field' => $humanization->field,
                'error_code' => $errorCode,
                'exception_class' => $throwable::class,
            ]);

            return $this->failSubmission($humanization, $errorCode);
        }

        if ($result === null
            || !in_array($result->status, ['queued', 'processing', 'done'], true)
            || $result->operationId !== $humanization->operation_id) {
            $errorCode = $result->errorCode ?? 'invalid_response';

            Log::error('AI humanization submission returned a terminal failure', [
                'model' => $humanization->humanizable_type,
                'model_id' => $humanization->humanizable_id,
                'locale' => $humanization->locale,
                'field' => $humanization->field,
                'operation_id' => $humanization->operation_id,
                'error_code' => $errorCode,
            ]);

            return $this->failSubmission($humanization, $errorCode, $result?->status);
        }

        $humanization->update([
            'submission_state' => 'accepted',
            'phasea_status' => $result->status,
            'error_code' => null,
        ]);

        // Same-operation_id replay statuses (audit D4): 'processing' → phasea
        // already accepted this operation; keep polling. 'done' → phasea
        // completed the work while we were crash-recovering — run the normal
        // poll terminal path immediately (it re-reads the operation and reuses
        // the existing write-back logic) instead of failing 'invalid_response'.
        if ($result->status === 'done') {
            app(HumanizationPollService::class)->poll($humanization->id);
        } else {
            $this->schedulePoll($humanization->id);
        }

        return $humanization->refresh();
    }

    private function failSubmission(
        AiHumanization $humanization,
        string $errorCode,
        ?string $phaseaStatus = null,
    ): AiHumanization {
        $humanization->update([
            'status' => 'failed',
            'submission_state' => 'terminal',
            'phasea_status' => $phaseaStatus,
            'error_code' => $errorCode,
            'active_intent_key' => null,
        ]);

        app(AiOperationContinuationService::class)->release($humanization->refresh());

        return $humanization->refresh();
    }

    private function schedulePoll(int $humanizationId): void
    {
        PollAiHumanizationJob::dispatch($humanizationId)
            ->onQueue((string) config('ai.assistant.ai_humanization.queue', 'ai-bulk'))
            ->delay(now()->addSeconds(
                (int) config('ai.assistant.ai_humanization.poll_interval_seconds', 5),
            ));
    }
}
