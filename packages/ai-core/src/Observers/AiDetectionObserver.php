<?php

declare(strict_types=1);

namespace Core45\AiCore\Observers;

use Core45\AiCore\Models\Concerns\HasAiDetection;
use Core45\AiCore\Services\Operations\DetectionFlowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Triggers AI-content detection when a detectable model is saved.
 *
 * NOTE: the source implementation dispatched a bespoke host `RunAiDetectionJob`
 * that called a host `AiDetectorService` (a direct HTTP integration with
 * undetectable.ai). Neither of those classes exists in this package. The
 * `core45/ai-core` package ships an equivalent, more complete detection flow
 * (`Core45\AiCore\Services\Operations\DetectionFlowService`, backed by the
 * phasea remote AI client and `Core45\AiCore\Jobs\AiOperations\PollAiDetectionJob`
 * for async polling), so this observer submits directly to that service instead
 * of dispatching a job of its own.
 */
class AiDetectionObserver
{
    public function __construct(private readonly DetectionFlowService $detectionFlowService) {}

    public function saved(Model $model): void
    {
        if (!(bool) config('ai.assistant.ai_detection.enabled', false)) {
            return;
        }

        if (!in_array(HasAiDetection::class, class_uses_recursive($model), true)) {
            return;
        }

        if (!$model->hasDetectableContentChanged()) { // @phpstan-ignore method.notFound
            return;
        }

        if ($model->hasAiDetectionPending()) { // @phpstan-ignore method.notFound
            return;
        }

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supportedLocales) as $locale) {
            $this->detectionFlowService->submit($model, $locale, (string) Str::uuid());
        }
    }
}
