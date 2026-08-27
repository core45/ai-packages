<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Core45\AiCore\Jobs\AiOperations\PollAiHumanizationJob;
use Core45\AiCore\Models\AiHumanization;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\AiOperations\ClientReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class HumanizationPollService
{
    use ConvertsThrowableToErrorCode;

    public function poll(int $humanizationId): void
    {
        $humanization = AiHumanization::find($humanizationId);

        if (!$humanization instanceof AiHumanization) {
            Log::warning('AI humanization poll row was not found', [
                'humanization_id' => $humanizationId,
            ]);

            return;
        }

        if ($humanization->isDone()) {
            $this->writeBack($humanization);

            return;
        }

        if ($humanization->isFailed()) {
            app(AiOperationContinuationService::class)->release($humanization);

            return;
        }

        if ($humanization->submission_state !== 'accepted') {
            return;
        }

        if ($humanization->poll_give_up_at?->isPast() === true) {
            $humanization->update([
                'status' => 'failed',
                'error_code' => 'poll_give_up',
                'active_intent_key' => null,
            ]);
            app(AiOperationContinuationService::class)->release($humanization->refresh());

            return;
        }

        try {
            $body = app(RemoteAiClient::class)->pollOperation(
                'humanize',
                (string) $humanization->operation_id,
            );
        } catch (Throwable $throwable) {
            $errorCode = $this->errorCodeForThrowable($throwable);

            $humanization->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'active_intent_key' => null,
            ]);
            app(AiOperationContinuationService::class)->release($humanization->refresh());

            Log::error('AI humanization poll failed', [
                'humanization_id' => $humanization->id,
                'model' => $humanization->humanizable_type,
                'model_id' => $humanization->humanizable_id,
                'locale' => $humanization->locale,
                'field' => $humanization->field,
                'error_code' => $errorCode,
                'exception_class' => $throwable::class,
            ]);

            return;
        }

        if (!$this->hasValidCorrelation($body, $humanization)) {
            $this->failInvalidResponse($humanization);

            return;
        }

        $status = $body['status'] ?? null;

        if (!is_string($status)) {
            $this->failInvalidResponse($humanization);

            return;
        }

        if (in_array($status, ['queued', 'processing'], true)) {
            $humanization->update(['phasea_status' => $status]);
            $this->schedulePoll($humanization->id);

            return;
        }

        if ($status === 'done') {
            $humanizedText = data_get($body, 'result.humanized_text');

            if (!is_string($humanizedText) || trim($humanizedText) === '') {
                $humanization->update([
                    'status' => 'failed',
                    'phasea_status' => 'done',
                    'error_code' => 'result_expired',
                    'active_intent_key' => null,
                ]);
                app(AiOperationContinuationService::class)->release($humanization->refresh());

                Log::error('AI humanization result expired before phaseb consumed it', [
                    'humanization_id' => $humanization->id,
                    'operation_id' => $humanization->operation_id,
                    'error_code' => 'result_expired',
                ]);

                return;
            }

            $humanization->update([
                'status' => 'done',
                'phasea_status' => 'done',
                'humanized_text' => $humanizedText,
                'external_id' => $this->vendorDocumentId($body),
            ]);

            $this->writeBack($humanization->refresh());

            return;
        }

        if (in_array($status, ['failed', 'submission_outcome_unknown'], true)) {
            $errorCode = data_get($body, 'failure.error_code', 'unknown');
            $safeErrorCode = is_string($errorCode) ? $errorCode : 'unknown';

            $humanization->update([
                'status' => 'failed',
                'phasea_status' => $status,
                'error_code' => $safeErrorCode,
                'external_id' => $this->vendorDocumentId($body),
                'active_intent_key' => null,
            ]);

            app(AiOperationContinuationService::class)->release($humanization->refresh());

            if ($status === 'submission_outcome_unknown') {
                Log::error('AI humanization submission_outcome_unknown; never auto-resubmitted', [
                    'humanization_id' => $humanization->id,
                    'operation_id' => $humanization->operation_id,
                    'error_code' => $safeErrorCode,
                ]);
            }

            return;
        }

        $this->failInvalidResponse($humanization);
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

    private function schedulePoll(int $humanizationId): void
    {
        PollAiHumanizationJob::dispatch($humanizationId)
            ->onQueue((string) config('ai.assistant.ai_humanization.queue', 'ai-bulk'))
            ->delay(now()->addSeconds(
                (int) config('ai.assistant.ai_humanization.poll_interval_seconds', 5),
            ));
    }

    private function failInvalidResponse(AiHumanization $humanization): void
    {
        $humanization->update([
            'status' => 'failed',
            'error_code' => 'invalid_response',
            'active_intent_key' => null,
        ]);

        app(AiOperationContinuationService::class)->release($humanization->refresh());

        Log::error('AI humanization poll returned an invalid status', [
            'humanization_id' => $humanization->id,
            'operation_id' => $humanization->operation_id,
            'error_code' => 'invalid_response',
        ]);
    }

    private function writeBack(AiHumanization $humanization): void
    {
        if ($humanization->written_back_at !== null) {
            app(AiOperationContinuationService::class)->release($humanization);

            return;
        }

        try {
            /** @var class-string<Model> $modelClass */
            $modelClass = $humanization->humanizable_type;
            $model = $modelClass::query()->find($humanization->humanizable_id);

            if (!$model instanceof Model) {
                $humanization->update(['written_back_at' => now()]);

                Log::info('AI humanization write-back skipped because the model no longer exists', [
                    'humanization_id' => $humanization->id,
                    'model' => $humanization->humanizable_type,
                    'model_id' => $humanization->humanizable_id,
                ]);

                app(AiOperationContinuationService::class)->release($humanization->refresh());

                return;
            }

            if (!$model instanceof TranslatableContract) {
                throw new \UnexpectedValueException('The humanization target is not translatable.');
            }

            $translation = $model->translateOrNew($humanization->locale);
            $currentText = (string) ($translation->{$humanization->field} ?? '');

            if ($currentText === (string) $humanization->humanized_text) {
                $humanization->update(['written_back_at' => now()]);
                app(AiOperationContinuationService::class)->release($humanization->refresh());

                return;
            }

            if ($currentText !== $humanization->original_text) {
                $humanization->update([
                    'status' => 'failed',
                    'error_code' => 'write_back_conflict',
                ]);
                app(AiOperationContinuationService::class)->release($humanization->refresh());

                Log::warning('AI humanization write-back skipped because the source text changed', [
                    'humanization_id' => $humanization->id,
                    'model' => $humanization->humanizable_type,
                    'model_id' => $humanization->humanizable_id,
                    'locale' => $humanization->locale,
                    'field' => $humanization->field,
                    'error_code' => 'write_back_conflict',
                ]);

                return;
            }

            $translation->{$humanization->field} = $humanization->humanized_text;
            $model->save();
            $humanization->update(['written_back_at' => now()]);
            app(AiOperationContinuationService::class)->release($humanization->refresh());
        } catch (Throwable $throwable) {
            $humanization->update([
                'status' => 'failed',
                'error_code' => 'write_back_failed',
            ]);
            app(AiOperationContinuationService::class)->release($humanization->refresh());

            Log::error('AI humanization write-back failed', [
                'humanization_id' => $humanization->id,
                'model' => $humanization->humanizable_type,
                'model_id' => $humanization->humanizable_id,
                'locale' => $humanization->locale,
                'field' => $humanization->field,
                'error_code' => 'write_back_failed',
                'exception_class' => $throwable::class,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hasValidCorrelation(array $body, AiHumanization $humanization): bool
    {
        $operationId = $body['operation_id'] ?? null;
        $surface = $body['surface'] ?? null;
        $clientReference = $body['client_reference'] ?? null;

        return is_string($operationId)
            && hash_equals((string) $humanization->operation_id, $operationId)
            && $surface === 'humanize'
            && is_string($clientReference)
            && ClientReference::isValid($clientReference)
            && hash_equals((string) $humanization->client_reference, $clientReference);
    }
}
