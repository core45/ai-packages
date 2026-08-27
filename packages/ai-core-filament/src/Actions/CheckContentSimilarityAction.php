<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Services\ContentSimilarityService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/**
 * On-demand similarity report for a single record.
 *
 * Runs {@see ContentSimilarityService} across every configured locale for
 * the given fields and renders the result in a read-only modal. Moved out
 * of a consuming application's Filament page together with its Blade view
 * (`ai-core::components.similarity-results`) so the whole flow ships with
 * the service it drives.
 */
class CheckContentSimilarityAction
{
    /**
     * @var list<string>
     */
    private const DEFAULT_FIELDS = ['excerpt', 'main_text', 'seo_description'];

    /**
     * @param  list<string>|null  $fields  content fields to compare; defaults to excerpt/main_text/seo_description
     */
    public static function make(?string $name = null, ?array $fields = null): Action
    {
        $fields ??= self::DEFAULT_FIELDS;

        return Action::make($name ?? 'checkSimilarity')
            ->label(__('Check Similarity'))
            ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
            ->color('info')
            ->modalHeading(__('Content Similarity Check'))
            ->modalDescription(__('Analyzing content similarity for this post...'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(function (AIContentable&Model $record) use ($fields): View {
                $similarityService = app(ContentSimilarityService::class);

                $results = [];
                /** @var array<string> $locales */
                $locales = config('translatable.locales', ['en']);

                foreach ($fields as $field) {
                    foreach ($locales as $locale) {
                        $result = $similarityService->checkSimilarity($record, $field, $locale, [
                            'check_all' => true,
                        ]);

                        if ($result->highestScore > 0) {
                            $results[] = [
                                'field' => $field,
                                'locale' => $locale,
                                'score' => $result->highestScore,
                                'similar_to' => $result->mostSimilarTo,
                                'status' => $result->status,
                                'comparisons_count' => count($result->comparisons),
                            ];
                        }
                    }
                }

                return view('ai-core::components.similarity-results', [
                    'results' => $results,
                    'modelName' => self::resolveRecordLabel($record),
                ]);
            });
    }

    /**
     * Best-effort human label for the record. Translatable models expose the
     * title through `translate()`; anything else falls back to a plain
     * `title` attribute and finally to a generic label, so the package never
     * assumes a consuming application's model shape.
     */
    private static function resolveRecordLabel(Model $record): string
    {
        if (method_exists($record, 'translate')) {
            $translation = $record->translate();

            if ($translation !== null && filled($translation->title ?? null)) {
                return (string) $translation->title;
            }
        }

        if (filled($record->getAttribute('title'))) {
            return (string) $record->getAttribute('title');
        }

        return __('Post');
    }
}
