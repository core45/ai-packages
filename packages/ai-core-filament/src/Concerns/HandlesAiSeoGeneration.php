<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Concerns;

use Core45\AiCore\Services\ContentSummarizerService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Handles AI-powered SEO content generation events dispatched by Filament actions.
 *
 * Listens for Livewire events from GenerateSeoVariationsAction and
 * GenerateDescriptionVariationsAction, then calls ContentSummarizerService
 * and dispatches results back to the browser.
 *
 * NOTE: the source implementation also handled a `suggest-keyphrases` event
 * backed by a host-owned keyphrase-research service. That service does not
 * exist in this application and is not part of the `core45/ai-core` or
 * `core45/ai-posts` packages, so the keyphrase-suggestion listener was
 * stripped.
 */
trait HandlesAiSeoGeneration
{
    /**
     * Handle the generate-seo-variations event dispatched by GenerateSeoVariationsAction.
     *
     * Calls ContentSummarizerService to generate multiple meta description variations
     * and dispatches the results back to the browser.
     *
     * @param  array<string, mixed>  $context
     */
    #[On('generate-seo-variations')]
    public function handleGenerateSeoVariations(array $context): void
    {
        if (!(bool) config('ai.assistant.enabled', false)) {
            Notification::make()
                ->title(__('AI Assistant is disabled'))
                ->body(__('Enable the AI assistant in settings to use this feature.'))
                ->warning()
                ->send();

            return;
        }

        try {
            /** @var ContentSummarizerService $summarizerService */
            $summarizerService = app(ContentSummarizerService::class);

            $content = $context['content_excerpt'] ?? '';
            $title = $context['title'] ?? '';
            $locale = $context['locale'] ?? app()->getLocale();
            $keyphrase = $context['focus_keyphrase'] ?? null;

            if ($keyphrase === '') {
                $keyphrase = null;
            }

            $variations = $summarizerService->generateVariations(
                $content,
                $title,
                $locale,
                $keyphrase,
                3,
            );

            $this->dispatch('seo-variations-generated', variations: $variations);

            Notification::make()
                ->title(__('SEO variations generated'))
                ->body(__(':count variation(s) have been generated successfully.', ['count' => count($variations)]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Log::error('[HandlesAiSeoGeneration] handleGenerateSeoVariations failed', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);

            Notification::make()
                ->title(__('Failed to generate SEO variations'))
                ->body(__('An error occurred while generating variations. Please try again.'))
                ->danger()
                ->send();
        }
    }

    /**
     * Handle the generate-description-variations event dispatched by GenerateDescriptionVariationsAction.
     *
     * Calls ContentSummarizerService to generate meta description variations
     * and dispatches the results back to the browser.
     *
     * @param  array<string, mixed>  $context
     */
    #[On('generate-description-variations')]
    public function handleGenerateDescriptionVariations(array $context): void
    {
        if (!(bool) config('ai.assistant.enabled', false)) {
            Notification::make()
                ->title(__('AI Assistant is disabled'))
                ->body(__('Enable the AI assistant in settings to use this feature.'))
                ->warning()
                ->send();

            return;
        }

        try {
            /** @var ContentSummarizerService $summarizerService */
            $summarizerService = app(ContentSummarizerService::class);

            $content = $context['content_excerpt'] ?? '';
            $title = $context['title'] ?? '';
            $locale = $context['locale'] ?? app()->getLocale();
            $keyphrase = $context['focus_keyphrase'] ?? null;

            if ($keyphrase === '') {
                $keyphrase = null;
            }

            $variations = $summarizerService->generateVariations(
                $content,
                $title,
                $locale,
                $keyphrase,
                3,
            );

            $this->dispatch('description-variations-generated', variations: $variations);

            Notification::make()
                ->title(__('Description variations generated'))
                ->body(__(':count variation(s) have been generated successfully.', ['count' => count($variations)]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Log::error('[HandlesAiSeoGeneration] handleGenerateDescriptionVariations failed', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);

            Notification::make()
                ->title(__('Failed to generate description variations'))
                ->body(__('An error occurred while generating variations. Please try again.'))
                ->danger()
                ->send();
        }
    }
}
