<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs;

use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Models\SimilarityAuditBatch;
use Core45\AiCore\Services\ContentSimilarityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to run a similarity audit on a batch of models.
 *
 * Checks content similarity for all content fields and records
 * any issues (high similarity) found in the batch.
 *
 * NOTE: the source implementation hard-coded a map of specific host model
 * classes (a blog post model, a product model, a webinar model, and a
 * product-category relationship filter) to their content fields. None of
 * that is package-appropriate: the package must never name a host model.
 * The field map is now driven entirely by `ModelRegistry` (which maps a
 * host model class to its neutral registry key) plus
 * `config('ai.similarity.fields', [])` (keyed by that same registry key).
 * A host registers its models with `ModelRegistry` and lists their content
 * fields under `ai.similarity.fields` in its own config. An unmapped
 * registry key (or a model class not registered at all) falls back to a
 * single `description` field, matching the previous default behaviour for
 * unmapped models.
 */
class RunSimilarityAudit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600; // 1 hour for large audits

    public function __construct(
        public SimilarityAuditBatch $batch
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(ContentSimilarityService $similarityService, ModelRegistry $modelRegistry): void
    {
        Log::info('RunSimilarityAudit: Starting', [
            'batch_id' => $this->batch->id,
            'model_type' => $this->batch->model_type,
            'total_items' => $this->batch->total_items,
        ]);

        $this->batch->markAsProcessing();

        try {
            $this->processModels($similarityService, $modelRegistry);
            $this->batch->markAsCompleted();

            Log::info('RunSimilarityAudit: Completed', [
                'batch_id' => $this->batch->id,
                'processed' => $this->batch->processed_items,
                'issues_found' => $this->batch->issues_count,
            ]);
        } catch (\Exception $e) {
            Log::error('RunSimilarityAudit: Failed', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
            ]);

            $this->batch->markAsFailed();

            throw $e;
        }
    }

    protected function processModels(ContentSimilarityService $similarityService, ModelRegistry $modelRegistry): void
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->batch->model_type;
        $options = $this->batch->options;
        $fields = $this->getContentFieldsForModel($modelClass, $modelRegistry);

        /** @var array<string> $locales */
        $locales = config('translatable.locales', ['en']);

        // Get options for similarity checking
        $similarityOptions = [
            'check_all' => $options['check_all'] ?? true,
        ];

        // Threshold for reporting issues (default: warning level = 0.60)
        $threshold = (float) ($options['threshold'] ?? 0.60);

        // Query models based on options
        $query = $this->buildModelQuery($modelClass, $options);

        // Process in chunks to handle large datasets
        $query->chunk(100, function ($models) use ($similarityService, $fields, $locales, $similarityOptions, $threshold) {
            foreach ($models as $model) {
                $this->processModel($model, $similarityService, $fields, $locales, $similarityOptions, $threshold);
                $this->batch->incrementProcessed();
            }
        });
    }

    /**
     * Process a single model for similarity issues.
     *
     * @param  array<string>  $fields
     * @param  array<string>  $locales
     * @param  array<string, mixed>  $options
     */
    protected function processModel(
        Model $model,
        ContentSimilarityService $similarityService,
        array $fields,
        array $locales,
        array $options,
        float $threshold
    ): void {
        foreach ($fields as $field) {
            foreach ($locales as $locale) {
                try {
                    $result = $similarityService->checkSimilarity($model, $field, $locale, $options);

                    // Store the result
                    if ($result->highestScore > 0) {
                        $similarityService->storeResult($model, $field, $locale, $result);
                    }

                    // Record issue if above threshold
                    if ($result->highestScore >= $threshold) {
                        $this->batch->addIssue([
                            'item_id' => $model->getKey(),
                            'field' => $field,
                            'locale' => $locale,
                            'score' => $result->highestScore,
                            'similar_to' => $result->mostSimilarTo,
                            'similar_type' => $result->mostSimilarType,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('RunSimilarityAudit: Failed to check model', [
                        'batch_id' => $this->batch->id,
                        'model_id' => $model->getKey(),
                        'field' => $field,
                        'locale' => $locale,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Get content fields to check for a model type.
     *
     * Resolves the model's `ModelRegistry` key and looks it up in
     * `config('ai.similarity.fields', [])`. Falls back to a single
     * `description` field when the model is unregistered or unmapped.
     *
     * @param  class-string  $modelClass
     * @return array<string>
     */
    protected function getContentFieldsForModel(string $modelClass, ModelRegistry $modelRegistry): array
    {
        $key = $modelRegistry->getModelKey($modelClass);

        /** @var array<string, array<string>> $fieldMap */
        $fieldMap = config('ai.similarity.fields', []);

        if ($key !== null && isset($fieldMap[$key])) {
            return $fieldMap[$key];
        }

        return ['description'];
    }

    /**
     * Build the query for models to audit.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $options
     * @return Builder<Model>
     */
    protected function buildModelQuery(string $modelClass, array $options): Builder
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        // Filter by specific IDs if provided
        if (!empty($options['item_ids'])) {
            $query->whereIn('id', $options['item_ids']);
        }

        // Only items with AI-generated content if specified
        if ($options['ai_generated_only'] ?? false) {
            if (in_array('ai_rewritten_at', (new $modelClass)->getFillable(), true)) {
                $query->whereNotNull('ai_rewritten_at');
            }
        }

        return $query;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RunSimilarityAudit: Permanently failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->markAsFailed();
    }
}
