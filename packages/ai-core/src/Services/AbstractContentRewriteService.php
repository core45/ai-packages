<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\DTOs\SimilarityResult;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Abstract base class for AI content rewrite services.
 *
 * Provides shared functionality for rewriting, translating, and generating
 * content for translatable Eloquent models using AI.
 *
 * Child classes must implement model-specific methods for:
 * - Field definitions (content fields, SEO fields)
 * - Context building
 * - Prompt customization
 * - Name/slug handling
 *
 * @phpstan-type TranslatableModel \Illuminate\Database\Eloquent\Model&object{id: int, translate: callable, translateOrNew: callable, refresh: callable}
 */
abstract class AbstractContentRewriteService
{
    use LogsAIOperations;

    /**
     * D2 hook: subclasses list the model classes whose `updated_at` should be
     * touched after a translation write. Replaces direct Post/Product imports;
     * strings resolve at runtime in the subclass's own namespace scope.
     *
     * @var list<class-string<Model>>
     */
    protected array $touchableModelClasses = [];

    /**
     * Model classes whose `updated_at` should be touched after a translation save.
     *
     * Override this METHOD, not the property, when the class has to come from
     * `ModelRegistry` — a property initializer cannot call `app()`. The property
     * remains the default so subclasses that still list host classes literally
     * keep working unchanged.
     *
     * @return array<int, class-string<Model>>
     */
    protected function getTouchableModelClasses(): array
    {
        return $this->touchableModelClasses;
    }

    protected ?int $userId = null;

    protected bool $useTranslationProvider = false;

    protected ?ChecksContentSimilarity $similarityService = null;

    /** @var array<string, mixed> Similarity options for content generation */
    protected array $similarityOptions = [];

    private ?\Closure $cancellationCallback = null;

    private int $aiCallCount = 0;

    /**
     * Caller-supplied operation ID (e.g. a Translate*ToLocale job's minted
     * `operationId` property), threaded into AI-call logging for
     * cross-referencing job runs. Falls back to a per-invocation UUID in
     * `callAI()` when unset — see SEAM-08 (plan 08-15) operationId threading.
     */
    private ?string $operationId = null;

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTRACT METHODS - Child classes MUST implement
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Define content fields to process (Phase 2).
     *
     * @return array<string, string> Field name => type ('plain' or 'rich')
     */
    abstract protected function getContentFields(): array;

    /**
     * Define SEO fields to process (Phase 3).
     *
     * @return array<string, string> Field name => type ('plain' or 'rich')
     */
    abstract protected function getSeoFields(): array;

    /**
     * Get the Eloquent model class.
     *
     * @return class-string<Model>
     */
    abstract protected function getModelClass(): string;

    /**
     * Get the translation model class.
     *
     * @return class-string<Model>
     */
    abstract protected function getTranslationClass(): string;

    /**
     * Get service name for logging.
     */
    abstract protected function getServiceName(): string;

    /**
     * Get content type label for prompts (e.g., 'blog posts', 'products').
     */
    abstract protected function getContentTypeLabel(): string;

    /**
     * Get prompt override key for main system prompt.
     */
    abstract protected function getPromptOverrideKey(): string;

    /**
     * Get prompt override key for SEO system prompt.
     */
    abstract protected function getSeoPromptOverrideKey(): string;

    /**
     * Build model-specific context for AI prompts.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildModelContext(Model $model): array;

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5, plan 48-07).
     *
     * ABSTRACT, unlike `getSeoContentTypeKey()`, which is non-abstract with a
     * throwing default. The asymmetry is deliberate and reflects a real
     * difference: only four subclasses have SEO fields, but ALL SIX rewrite and
     * generate content, so every one of them must supply this key. Abstract
     * makes that a load-time contract rather than a runtime throw.
     *
     * NOT `getContentTypeLabel()`, which is a human phrase for the instruction
     * text itself ('blog posts', 'products'), and NOT `imageMarkerVariant()`,
     * which is a two-value marker selector. This is a stable machine key.
     */
    abstract protected function getContentTypeKey(): string;

    /**
     * Get field description for prompts.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * D-48-4 TRANSLATE-ONLY REMNANT — DELIBERATELY NOT DELETED (plan 48-07).
     *
     * Plan 48-07 was written to delete this method and its six implementations.
     * It cannot be: `buildTranslatePrompt()` interpolates `{$fieldDescription}`
     * TWICE, and translation is the D-48-4 carve-out owned by phases 41–43. The
     * sole remaining caller in `app/` is `buildTranslatePrompt()` —
     * `rewriteField()` and `generateField()` now send `getContentTypeKey()` plus
     * the field name and let phasea's `FieldDescriptions` table select the
     * wording.
     *
     * This is exactly the `getFormatInstructions()` situation plan 48-05
     * recorded: the phasea/phaseb duplication is intentional and temporary, and
     * it ends when the translate path migrates. The `seo_*` arms and the
     * `default` arm live on here for the same reason — they are reachable only
     * through translation, which is why phasea's table does not reproduce them.
     *
     * Enumerated for plan 48-12's guard allowlist. These values are AUTHORED
     * PROMPT TEXT under a data-lookup name, and they are the interpolated
     * single-line shape that evaded three consecutive inventories — a
     * heredoc/persona grep will not find them.
     * ─────────────────────────────────────────────────────────────────────────
     */
    abstract protected function getFieldDescription(string $field): string;

    /**
     * Build SEO-specific context from freshly generated content.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    abstract protected function buildSeoContext(Model $model, string $locale, array $context): array;

    // v2.6 design §2.5 — `abstract protected function buildSeoFieldPrompt()` was
    // declared here. The SEO field instructions moved to phasea's
    // `rewrite.seo_field` template, selected by `getSeoContentTypeKey()`.
    //
    // T-48-22, WITH THE ORDER CORRECTED. The threat is that a half-removed
    // abstract method fatals. Only ONE of the two directions actually does:
    // removing an IMPLEMENTATION while the abstract declaration stands is a
    // fatal ("Class D contains 1 abstract method…"); removing the DECLARATION
    // while subclasses still implement it is legal — their overrides simply
    // become ordinary methods with no caller. So the declaration goes first and
    // the implementations follow, which is the order in which no intermediate
    // state can fatal. Plan 48-07 removes the two remaining orphaned overrides
    // on `WebinarPartContentRewriteService` and
    // `WebinarPartElementContentRewriteService`, both of which return `''` and
    // hold no prompt text.

    /**
     * Handle name/slug generation (model-specific logic).
     *
     * @param  array<string, mixed>  $context
     */
    abstract protected function handleNameSlug(Model $model, string $locale, array $context): void;

    /**
     * Find source locale for a field.
     */
    abstract protected function findSourceLocale(Model $model, string $field): ?string;

    /**
     * Check if slug exists for locale (model-specific query).
     */
    abstract protected function slugExistsForLocale(string $slug, int $excludeId, string $locale): bool;

    /**
     * Resolve the content archetype for a model.
     *
     * Should use the model's HasContentArchetype trait if available,
     * or return null if the model doesn't support archetypes.
     */
    abstract protected function resolveArchetype(Model $model): ?Model;

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTRUCTOR & SETUP
    // ═══════════════════════════════════════════════════════════════════════

    /*
     * v2.6: the $provider/$model parameters and properties were removed. Their
     * only reader was resolveReasoningEffort(), deleted in 706c97b, which left
     * them assigned-and-never-read. No caller passed either argument. The host
     * does not choose models — phasea routes each task.
     */

    /**
     * Set the operation ID for subsequent AI call(s) (e.g. threaded from a
     * Translate*ToLocale job's `operationId` property). Falls back to a
     * per-invocation UUID in `callAI()` when unset.
     *
     * Multi-call flows (per-field translation loops, chunked rich-text
     * translation) must NOT reuse this ID verbatim across distinct calls —
     * phasea meters usage by (task, operation_id), so duplicate IDs collapse
     * distinct billable calls. Use `withScopedOperationId()` to derive a
     * deterministic UUIDv5 per unit of work: stable across job retries
     * (idempotent — no re-billing, audit 5.4) yet unique per field/chunk.
     */
    public function setOperationId(?string $operationId): static
    {
        $this->operationId = $operationId;

        return $this;
    }

    /**
     * Run `$callback` with a deterministic operation ID derived from the
     * currently set one: `Uuid::uuid5($current, $scopeKey)` — the exact
     * pattern TranslatePostToLocale already uses for per-FAQ IDs (IN-08).
     * Restores the previous ID afterwards so sibling scopes derive from the
     * same parent. No-op passthrough when no operation ID is set (callAI()
     * then mints a fresh UUID per invocation, the pre-threading behavior).
     */
    protected function withScopedOperationId(string $scopeKey, \Closure $callback): mixed
    {
        if ($this->operationId === null) {
            return $callback();
        }

        $parentOperationId = $this->operationId;
        $this->operationId = Uuid::uuid5($parentOperationId, $scopeKey)->toString();

        try {
            return $callback();
        } finally {
            $this->operationId = $parentOperationId;
        }
    }

    /**
     * Mark subsequent calls as translation work.
     *
     * v2.6: this no longer selects a provider or model — phasea routes the
     * translate task. It survives because `resolveTimeout()` still uses the flag
     * to pick the `translation` HTTP timeout instead of `content_rewrite`, which
     * is a host-side transport concern and legitimately the host's to make.
     */
    public function forTranslation(): static
    {
        $this->useTranslationProvider = true;

        return $this;
    }

    /**
     * Set the user ID for usage tracking (used when running in queue context).
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Enable similarity checking during content generation.
     *
     * @param  array<string, mixed>  $options
     */
    public function withSimilarityChecking(array $options = []): self
    {
        $this->similarityService = app(ChecksContentSimilarity::class);
        $this->similarityOptions = array_merge([
            'check_same_category' => config('system-settings.ai-similarity-check-category', false),
            'check_all' => config('system-settings.ai-similarity-check-all', false),
            'external_source_ids' => config('system-settings.ai-similarity-external-sources', []),
            'max_regeneration_attempts' => 3,
        ], $options);

        return $this;
    }

    public function onCancellation(\Closure $callback): static
    {
        $this->cancellationCallback = $callback;

        return $this;
    }

    public function isCancelled(): bool
    {
        if ($this->cancellationCallback) {
            return ($this->cancellationCallback)();
        }

        return false;
    }

    /**
     * Disable similarity checking.
     */
    public function withoutSimilarityChecking(): self
    {
        $this->similarityService = null;
        $this->similarityOptions = [];

        return $this;
    }

    /**
     * Check if similarity checking is enabled.
     */
    protected function isSimilarityCheckingEnabled(): bool
    {
        return $this->similarityService !== null && !empty($this->similarityOptions);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TEMPLATE METHOD - Main Processing Algorithm
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Main processing template - shared algorithm for all content types.
     *
     * @param  Model&Translatable  $model
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    protected function processModel(Model $model): array
    {
        $locales = $this->getLocales();
        /** @phpstan-ignore property.notFound */
        $modelId = (int) $model->id;
        $results = [
            'model_id' => $modelId,
            'processed_fields' => [],
            'errors' => [],
        ];

        $this->logRequest($this->getServiceName(), [
            'model_id' => $modelId,
            'locales' => $locales,
        ]);

        return $this->withQueryLogging($this->getServiceName(), function () use ($model, $locales, &$results) {
            $context = $this->buildModelContext($model);

            // Phase 1: Handle name/title + slug (only if missing)
            foreach ($locales as $locale) {
                try {
                    $this->handleNameSlug($model, $locale, $context);
                    $results['processed_fields'][] = "name:{$locale}";
                } catch (\Exception $e) {
                    $results['errors'][] = "name:{$locale} - ".$this->sanitizeErrorMessage($e);
                    $this->logError($this->getServiceName().' - handleNameSlug', $e);
                }
            }

            // Phase 2: Process CONTENT fields (with optional similarity checking)
            foreach ($this->getContentFields() as $field => $type) {
                foreach ($locales as $locale) {
                    try {
                        $content = $this->processContentFieldWithSimilarity(
                            $model,
                            $field,
                            $locale,
                            $type,
                            $context
                        );
                        $results['processed_fields'][] = "{$field}:{$locale}";
                    } catch (\Exception $e) {
                        $results['errors'][] = "{$field}:{$locale} - ".$this->sanitizeErrorMessage($e);
                        $this->logError($this->getServiceName().' - rewriteOrGenerateField', $e);
                    }
                }
            }

            // Refresh to get newly generated content for SEO context
            $model->refresh();

            // Phase 3: Process SEO fields (uses fresh content as context)
            foreach ($this->getSeoFields() as $field => $type) {
                foreach ($locales as $locale) {
                    try {
                        $content = $this->generateSeoField($model, $field, $locale, $context);
                        $this->saveTranslation($model, $field, $locale, $content);
                        $results['processed_fields'][] = "{$field}:{$locale}";
                    } catch (\Exception $e) {
                        $results['errors'][] = "{$field}:{$locale} - ".$this->sanitizeErrorMessage($e);
                        $this->logError($this->getServiceName().' - generateSeoField', $e);
                    }
                }
            }

            // Phase 3b: Generate focus keyphrases for SeoIndexable
            $this->processFocusKeyphrases($model, $locales, $context, $results);

            // Phase 3c: Citation enrichment for GEO.
            //
            // The config gates are tested BEFORE the container is asked for the
            // enricher. They used to be tested after, so a disabled feature
            // still resolved its service — and in an application that had not
            // bound one, threw. A flag that cannot prevent the work its own
            // feature does is not a flag.
            $citationEnricher = config('geo.citation.enabled') && config('geo.citation.in_rewrite_pipeline')
                ? app(EnrichesCitations::class)
                : null;

            if ($citationEnricher !== null && $citationEnricher->supports($model)) {
                foreach ($locales as $locale) {
                    try {
                        $citationEnricher->enrich($model, $locale);
                        $results['processed_fields'][] = "citation_block:{$locale}";
                    } catch (\Exception $e) {
                        $results['errors'][] = "citation_block:{$locale} - ".$this->sanitizeErrorMessage($e);
                    }
                }
            }

            // Phase 4: Optional fields (hook for child classes)
            $this->processOptionalFields($model, $locales, $context, $results);

            // Phase 5: Media generation (hook for child classes)
            $this->processMediaGeneration($model, $results);

            $model->refresh();

            $this->logResponse($this->getServiceName(), $results);

            return $results;
        });
    }

    /**
     * Hook method for optional fields - override in child classes if needed.
     *
     * @param  array<string>  $locales
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $results
     */
    protected function processOptionalFields(Model $model, array $locales, array $context, array &$results): void
    {
        // Default: no optional fields
        // ProductContentRewriteService overrides this for specifications, user_manual, etc.
    }

    /**
     * Hook method for media generation (Phase 5) - override in child classes if needed.
     *
     * @param  array<string, mixed>  $results
     */
    protected function processMediaGeneration(Model $model, array &$results): void
    {
        // Default: no media generation
        // ProductContentRewriteService overrides this for AI image generation
    }

    /**
     * Hook method to get image marker instructions for rich content prompts.
     * Override in child classes to inject [IMAGE:prompt] placement instructions.
     *
     * D-48-4 — TRANSLATE ONLY as of plan 48-05. The rewrite, generate and
     * unique-sections paths send `include_image_markers`/`image_marker_variant`
     * and phasea's `RewriteFormatInstructions` renders the block; the sole
     * remaining consumer of this hook is `getFormatInstructions()` <-
     * `buildTranslatePrompt()`, which phases 41–43 own. It goes when translation
     * migrates, together with `imageMarkersEnabled()`'s legacy second reader.
     */
    protected function getImageMarkerInstructions(): string
    {
        return '';
    }

    /**
     * Generate and save focus keyphrases to SeoIndexable for each locale.
     *
     * Only runs for models that use the HasSeoIndexable trait.
     *
     * @param  Model&Translatable  $model
     * @param  array<string>  $locales
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $results
     */
    protected function processFocusKeyphrases(Model $model, array $locales, array $context, array &$results): void
    {
        if (!app(StoresFocusKeyphrases::class)->supports($model)) {
            return;
        }

        foreach ($locales as $locale) {
            try {
                $keyphrase = $this->generateFocusKeyphrase($model, $locale, $context);
                $this->saveFocusKeyphrase($model, $locale, $keyphrase);
                $results['processed_fields'][] = "focus_keyphrase:{$locale}";
            } catch (\Exception $e) {
                $results['errors'][] = "focus_keyphrase:{$locale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - generateFocusKeyphrase', $e);
            }
        }
    }

    /**
     * Generate a focus keyphrase for a model in a specific locale.
     *
     * v2.6 design §2.5: runs phasea's `rewrite.focus_keyphrase` template. The
     * host still resolves WHICH content and WHICH locale — `buildSeoContext()`
     * reads this host's models — but hands the result over as a structured
     * array rather than as a rendered context block.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function generateFocusKeyphrase(Model $model, string $locale, array $context): string
    {
        $keyphrase = $this->callPromptTemplate(
            'rewrite.focus_keyphrase',
            $this->buildSeoPromptParameters($model, $locale, $context),
            'plain',
            AiSurface::Rewrite,
        );

        // Enforce max length of 100 characters (matching FocusKeyphraseInput maxLength)
        if (mb_strlen($keyphrase) > 100) {
            $keyphrase = mb_substr($keyphrase, 0, 100);
            $lastSpace = mb_strrpos($keyphrase, ' ');
            if ($lastSpace !== false && $lastSpace > 50) {
                $keyphrase = mb_substr($keyphrase, 0, $lastSpace);
            }
        }

        return trim($keyphrase);
    }

    /**
     * Save a focus keyphrase to the SeoIndexable model.
     *
     * @param  Model&Translatable  $model
     */
    protected function saveFocusKeyphrase(Model $model, string $locale, string $keyphrase): void
    {
        app(StoresFocusKeyphrases::class)->save($model, $locale, $keyphrase);

        $this->logDebug($this->getServiceName().' - Focus keyphrase saved', [
            'model_id' => $model->getKey(),
            'locale' => $locale,
            'keyphrase' => $keyphrase,
        ]);
    }

    /**
     * Process content field with optional similarity checking.
     *
     * When similarity checking is enabled, wraps the content generation
     * with similarity detection and auto-regeneration/enhancement.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function processContentFieldWithSimilarity(
        Model $model,
        string $field,
        string $locale,
        string $type,
        array $context
    ): string {
        // Define the generation callback
        $generateCallback = fn (): string => $this->rewriteOrGenerateField($model, $field, $locale, $type, $context);

        // If similarity checking is enabled, use it
        if ($this->isSimilarityCheckingEnabled()) {
            return $this->generateWithSimilarityCheck(
                $model,
                $field,
                $locale,
                $type,
                $context,
                $generateCallback
            );
        }

        // Otherwise, generate normally and save
        $content = $generateCallback();
        $this->saveTranslation($model, $field, $locale, $content);

        return $content;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONTENT PROCESSING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Decide whether to rewrite, translate, or generate content for a field.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function rewriteOrGenerateField(
        Model $model,
        string $field,
        string $locale,
        string $type,
        array $context
    ): string {
        $translation = $model->translate($locale);
        $existingContent = $translation?->{$field};

        if (!empty($existingContent)) {
            return $this->rewriteField($model, $field, $locale, $type, $context);
        }

        $sourceLocale = $this->findSourceLocale($model, $field);

        if ($sourceLocale) {
            return $this->translateField($model, $field, $sourceLocale, $locale, $type, $context);
        }

        return $this->generateField($model, $field, $locale, $type, $context);
    }

    /**
     * Rewrite existing content to make it unique.
     *
     * v2.6 design §2.5: runs phasea's `rewrite.field_rewrite` template. The
     * archetype text, the effective writing profile, the tenant's prompt
     * overrides, the locale, the model context and the field's current value all
     * travel as DATA; no instruction text is assembled here.
     *
     * THE FIELD DESCRIPTION IS NO LONGER SENT. Plan 48-05's temporary
     * `field_description` parameter carried `getFieldDescription($field)`
     * already resolved — host-authored prompt text on the wire wearing a
     * parameter's name. This path now sends the two DISCRIMINATORS
     * (`content_type`, `field`) and phasea's `FieldDescriptions` table selects
     * the wording, so it is centrally tunable. An unknown pair 422s there rather
     * than rendering an empty description.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function rewriteField(
        Model $model,
        string $field,
        string $locale,
        string $type,
        array $context
    ): string {
        $translation = $model->translate($locale);
        $existingContent = $translation->{$field} ?? '';

        return $this->callPromptTemplate(
            'rewrite.field_rewrite',
            array_merge($this->buildSystemPromptParameters($locale, $context, $model), [
                'content_type' => $this->getContentTypeKey(),
                'field' => $field,
                'type' => $type,
                'existing_content' => (string) $existingContent,
            ]),
            $type,
            AiSurface::Rewrite,
        );
    }

    /**
     * Translate content from source locale to target locale.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function translateField(
        Model $model,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        string $type,
        array $context
    ): string {
        $sourceTranslation = $model->translate($sourceLocale);
        $sourceContent = $sourceTranslation->{$field} ?? '';

        $systemPrompt = $this->buildSystemPrompt($targetLocale, $context, $model);
        $userPrompt = $this->buildTranslatePrompt($field, $type, $sourceLocale, $targetLocale, $sourceContent);

        return $this->callAI($systemPrompt, $userPrompt, $type, $model, $field, $targetLocale, AiSurface::Translate);
    }

    /**
     * Generate new content from scratch based on context.
     *
     * v2.6 design §2.5: runs phasea's `rewrite.field_generate` template. Same
     * parameter set as the rewrite path minus `existing_content`, since there is
     * none.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function generateField(
        Model $model,
        string $field,
        string $locale,
        string $type,
        array $context
    ): string {
        return $this->callPromptTemplate(
            'rewrite.field_generate',
            array_merge($this->buildSystemPromptParameters($locale, $context, $model), [
                'content_type' => $this->getContentTypeKey(),
                'field' => $field,
                'type' => $type,
            ]),
            $type,
            AiSurface::Rewrite,
        );
    }

    /**
     * Simple text translation without rewriting.
     *
     * When type is 'rich', preserves all HTML structure including tags,
     * attributes (data-id, href, src, alt), and citation markers.
     */
    protected function translateText(string $text, string $sourceLocale, string $targetLocale, string $type = 'plain'): string
    {
        if ($type === 'rich') {
            // SERVER-SIDE CHUNKING CUTOVER (phase 11 / D1). When enabled, send the
            // WHOLE body in one call and let phasea chunk it model-derived — the
            // owner's actual requirement. The client-side chunk loop below is KEPT,
            // gated, for instant rollback; do NOT delete it. This flag is the last
            // step of the coordinated cutover and must only be ON once phasea's
            // async path is on (otherwise a long body 422s).
            if ((bool) config('ai.assistant.translation.server_side_chunking', false)) {
                return $this->translateRichChunk($text, $sourceLocale, $targetLocale);
            }

            $threshold = (int) config('ai.assistant.translation.chunk_threshold', 12000);

            if ($threshold > 0 && mb_strlen($text) > $threshold) {
                return $this->translateRichInChunks($text, $sourceLocale, $targetLocale, $threshold);
            }

            return $this->translateRichChunk($text, $sourceLocale, $targetLocale);
        }

        $prompt = "Translate the following text from {$sourceLocale} to {$targetLocale}. Return ONLY the translation, no explanations:\n\n{$text}";

        return $this->callAI('You are a professional translator.', $prompt, 'plain', null, null, null, AiSurface::Translate);
    }

    /**
     * Translate one HTML fragment in a single LLM call, preserving tags, attributes and citations.
     */
    private function translateRichChunk(string $html, string $sourceLocale, string $targetLocale): string
    {
        // THE RULES LIVE IN THE SYSTEM PROMPT, NOT THE USER PROMPT (phase 11 / D4).
        // On the server-side-chunking path phasea splits the USER prompt and prepends
        // only the SYSTEM-derived prefix to chunks 2..N (phasea D-41-4). If these
        // rules rode in the user prompt, chunks 2..N would arrive as bare HTML with
        // no "preserve tags / don't translate URLs" instruction — silently worse
        // translation on exactly the long posts server-side chunking targets. Keeping
        // them in the system prompt makes every chunk carry them. Harmless on the
        // client-chunked path too: the rules reach the model either way.
        $system = "You are a professional translator. Translate the HTML content the user provides from {$sourceLocale} to {$targetLocale}.\n\n"
            ."CRITICAL RULES:\n"
            ."- Return ONLY the translated HTML, no explanations or markdown wrappers\n"
            ."- Preserve ALL HTML tags exactly as they are (<a>, <p>, <h2>, <figure>, <img>, <strong>, <em>, etc.)\n"
            ."- Preserve ALL HTML attributes exactly (href, src, alt, data-id, data-auto-linked, class, etc.)\n"
            ."- Preserve ALL citation markers [1], [2], etc. exactly as-is\n"
            ."- Only translate visible text content between HTML tags\n"
            ."- Do NOT translate URLs, UUIDs, or attribute values\n"
            .'- Translate alt text in img tags';

        // The user prompt is the HTML ONLY — this is what phasea chunks. Always use
        // 'plain' type for callAI (no markdown-to-HTML conversion; the content is
        // already HTML and is preserved as-is).
        return $this->callAI($system, $html, 'plain', null, null, null, AiSurface::Translate);
    }

    /**
     * Translate a large HTML body by splitting it at top-level boundaries and translating
     * each chunk in its own LLM call, then concatenating the results.
     *
     * A single monolithic call on a long post body exceeds the HTTP timeout; chunking keeps
     * every request comfortably under it while preserving the document structure.
     */
    private function translateRichInChunks(string $html, string $sourceLocale, string $targetLocale, int $maxChars): string
    {
        $chunks = (new HtmlChunker)->split($html, $maxChars);
        $totalChunks = count($chunks);
        $cachePrefix = 'translate_chunk:'.md5($html).":$sourceLocale:$targetLocale";
        $translated = [];
        $cachedCount = 0;

        $this->logDebug($this->getServiceName().' - Chunked translation starting', [
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'total_chunks' => $totalChunks,
            'total_length' => mb_strlen($html),
            'chunk_threshold' => $maxChars,
        ]);

        foreach ($chunks as $index => $chunk) {
            $cacheKey = "$cachePrefix:$index";

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $cachedCount++;
                $translated[] = $cached;

                continue;
            }

            // Deterministic per-chunk operation id (audit 5.4): distinct per
            // chunk so phasea meters each billable call, stable across job
            // retries so already-billed chunks are not re-billed. In-chunk
            // retry attempts intentionally share the chunk's id (same work).
            /** @var string $result */
            $result = $this->withScopedOperationId(
                "chunk:{$index}",
                fn (): string => $this->translateChunkWithRetry($chunk, $sourceLocale, $targetLocale, $index, $totalChunks),
            );
            Cache::put($cacheKey, $result, now()->addHours(2));
            $translated[] = $result;
        }

        // All chunks succeeded — clean up cache
        for ($i = 0; $i < $totalChunks; $i++) {
            Cache::forget("$cachePrefix:$i");
        }

        $this->logDebug($this->getServiceName().' - Chunked translation completed', [
            'total_chunks' => $totalChunks,
            'from_cache' => $cachedCount,
            'translated' => $totalChunks - $cachedCount,
        ]);

        return implode('', $translated);
    }

    private function translateChunkWithRetry(string $chunk, string $sourceLocale, string $targetLocale, int $index, int $totalChunks): string
    {
        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->logDebug($this->getServiceName().' - Chunk '.($index + 1)."/$totalChunks", [
                    'attempt' => $attempt,
                    'length' => mb_strlen($chunk),
                ]);

                $startTime = microtime(true);
                $result = $this->translateRichChunk($chunk, $sourceLocale, $targetLocale);
                $duration = round(microtime(true) - $startTime, 1);

                $this->logDebug($this->getServiceName().' - Chunk '.($index + 1)."/$totalChunks OK", [
                    'duration_s' => $duration,
                    'result_length' => mb_strlen($result),
                ]);

                return $result;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A prior attempt already produced this chunk under its deterministic id.
                // Retrying just 409s again every time — rethrow immediately so the caller's
                // reconciliation runs now instead of after three pointless sleep-retries.
                throw $e;
            } catch (\Exception $e) {
                $lastException = $e;
                $this->logError($this->getServiceName().' - Chunk '.($index + 1)."/$totalChunks failed (attempt $attempt/$maxAttempts)", $e);

                if ($attempt < $maxAttempts) {
                    sleep(min($attempt * 10, 30));
                }
            }
        }

        $this->logError($this->getServiceName().' - Chunk '.($index + 1)."/$totalChunks exhausted all $maxAttempts attempts, giving up", $lastException);

        throw $lastException;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SIMILARITY-AWARE CONTENT GENERATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate content with similarity checking.
     *
     * When similarity checking is enabled:
     * - Checks generated content against existing content
     * - Auto-regenerates if similarity ≥ 75% (up to max attempts)
     * - Enhances with additional sections if similarity 60-75%
     * - Stores similarity check results in database
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function generateWithSimilarityCheck(
        Model $model,
        string $field,
        string $locale,
        string $type,
        array $context,
        callable $generateCallback
    ): string {
        // If similarity checking is disabled, just generate normally
        if (!$this->isSimilarityCheckingEnabled() || $this->similarityService === null) {
            return $generateCallback();
        }

        $maxAttempts = $this->similarityOptions['max_regeneration_attempts'] ?? 3;
        $attempt = 0;
        $content = '';
        $previousScore = 0.0;
        $mostSimilarTo = '';

        do {
            $attempt++;

            // Add similarity feedback to context for regeneration attempts
            if ($attempt > 1 && $mostSimilarTo !== '') {
                $context['similarity_feedback'] = "Previous content was {$previousScore}% similar to: {$mostSimilarTo}. Make this version MORE UNIQUE.";
            }

            $content = $generateCallback();

            // Save content first so similarity check can compare it
            $this->saveTranslation($model, $field, $locale, $content);
            $model->refresh();

            // Check similarity
            $similarity = $this->similarityService->checkSimilarity(
                $model,
                $field,
                $locale,
                $this->similarityOptions
            );

            // Pass if similarity is acceptable
            if ($similarity->passed()) {
                $this->storeSimilarityResult($model, $field, $locale, $similarity);

                return $content;
            }

            $mostSimilarTo = $similarity->mostSimilarTo;
            $previousScore = $similarity->getHighestScorePercentage();

            // If should regenerate and we have attempts left, continue loop
            if ($similarity->shouldRegenerate() && $attempt < $maxAttempts) {
                $this->logDebug($this->getServiceName().' - Similarity check failed, regenerating', [
                    'attempt' => $attempt,
                    'score' => $similarity->getHighestScorePercentage(),
                    'similar_to' => $mostSimilarTo,
                ]);

                continue;
            }

            // If warning level, try to enhance the content
            if ($similarity->shouldEnhance()) {
                $content = $this->enhanceContentWithUniqueSections($model, $field, $locale, $type, $content, $context);
                $this->saveTranslation($model, $field, $locale, $content);
                $model->refresh();

                // Re-check similarity after enhancement
                $similarity = $this->similarityService->checkSimilarity(
                    $model,
                    $field,
                    $locale,
                    $this->similarityOptions
                );
            }

            // Store final result regardless of status
            $this->storeSimilarityResult($model, $field, $locale, $similarity);

            return $content;

        } while ($attempt < $maxAttempts);

        return $content;
    }

    /**
     * Enhance content by adding unique sections to reduce similarity.
     *
     * v2.6 design §2.5: runs phasea's `rewrite.unique_sections` template. The
     * similarity FEEDBACK `generateWithSimilarityCheck()` writes into
     * `$context['similarity_feedback']` still reaches the model inside the
     * context JSON, so the similarity loop is behaviourally unchanged.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function enhanceContentWithUniqueSections(
        Model $model,
        string $field,
        string $locale,
        string $type,
        string $existingContent,
        array $context
    ): string {
        return $this->callPromptTemplate(
            'rewrite.unique_sections',
            array_merge($this->buildSystemPromptParameters($locale, $context, $model), [
                'type' => $type,
                'existing_content' => $existingContent,
            ]),
            $type,
            AiSurface::Rewrite,
        );
    }

    /**
     * Store similarity check result in database.
     *
     * @param  Model&Translatable  $model
     */
    protected function storeSimilarityResult(
        Model $model,
        string $field,
        string $locale,
        SimilarityResult $result
    ): void {
        if ($this->similarityService === null) {
            return;
        }

        $this->similarityService->storeResult($model, $field, $locale, $result);

        $this->logDebug($this->getServiceName().' - Similarity result stored', [
            'status' => $result->status,
            'score' => $result->getHighestScorePercentage(),
            'similar_to' => $result->mostSimilarTo,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SEO FIELD GENERATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate SEO field with specialized SEO-optimized prompts.
     *
     * v2.6 design §2.5: runs phasea's `rewrite.seo_field` template. ONE template
     * serves every content type — the per-content-type instruction is selected
     * by the validated `content_type` discriminator this host names, not by six
     * near-identical templates mirroring the six subclasses that used to hold
     * the text.
     *
     * The surface is still tagged `Rewrite`. No dedicated SEO surface exists in
     * phasea's `completeText` whitelist (16-01), so SEO field generation has
     * always billed and routed as a rewrite-shaped free-text completion;
     * phasea's template returns `AiTask::Rewrite` to keep that unchanged.
     *
     * `enforceSeoCharacterLimit()` still runs on the reply: it is
     * post-processing of a RESPONSE, not prompt authorship, so it stays here.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function generateSeoField(Model $model, string $field, string $locale, array $context): string
    {
        $content = $this->callPromptTemplate(
            'rewrite.seo_field',
            array_merge($this->buildSeoPromptParameters($model, $locale, $context), [
                'content_type' => $this->getSeoContentTypeKey(),
                'field' => $field,
            ]),
            'plain',
            AiSurface::Rewrite,
        );

        return $this->enforceSeoCharacterLimit($field, $content);
    }

    /**
     * Enforce hard character limits for SEO fields.
     */
    protected function enforceSeoCharacterLimit(string $field, string $content): string
    {
        $limits = [
            'seo_title' => 60,
            'seo_description' => 160,
        ];

        if (!isset($limits[$field])) {
            return $content;
        }

        $maxLength = $limits[$field];

        if (mb_strlen($content) <= $maxLength) {
            return $content;
        }

        // Truncate at word boundary to avoid cutting words in half
        $truncated = mb_substr($content, 0, $maxLength);

        // Find last space to avoid cutting mid-word
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        // Remove trailing punctuation that looks incomplete
        $truncated = rtrim($truncated, ' .,;:-');

        return $truncated;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROMPT BUILDERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Build main system prompt for content operations.
     *
     * When an archetype is available, uses the archetype's system prompt as the base
     * and applies the effective profile (archetype overrides + global settings).
     *
     * D-48-4 — TRANSLATE ONLY as of plan 48-05, and this is the whole reason it
     * still exists. It had three consumers; `rewriteField()` and
     * `generateField()` now call `buildSystemPromptParameters()` instead and
     * phasea's `RewriteSystemPromptComposer` does the writing. The one caller
     * left is `translateField()`, which phases 41–43 own.
     *
     * That makes this method and its four private helpers
     * (`buildArchetypeSystemPrompt`, `buildDefaultSystemPrompt`,
     * `buildProfileInstructions`, `getHumanWritingGuide`) a TRANSLATE-PATH
     * REMNANT rather than live rewrite scaffolding — the annotation plan 48-12's
     * guard allowlist is built from. They are duplicated in phasea on purpose
     * and the duplication ends when translation migrates.
     *
     * @param  array<string, mixed>  $context
     */
    protected function buildSystemPrompt(string $locale, array $context, ?Model $model = null): string
    {
        $archetype = $model ? $this->resolveArchetype($model) : null;
        $profile = $this->buildEffectiveProfile($archetype);

        $localeName = $this->getLocaleName($locale);
        $contentType = $this->getContentTypeLabel();
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';

        // Use archetype system prompt as base if available
        if ($archetype !== null && !empty($archetype->system_prompt)) {
            $basePrompt = $this->buildArchetypeSystemPrompt(
                $archetype,
                $profile,
                $localeName,
                $locale,
                $contextJson
            );
        } else {
            $basePrompt = $this->buildDefaultSystemPrompt(
                $profile,
                $localeName,
                $locale,
                $contentType,
                $contextJson
            );
        }

        return AIPromptOverride::applyOverrides($this->getPromptOverrideKey(), $basePrompt);
    }

    /**
     * The v2.6 counterpart of `buildSystemPrompt()`: the same inputs, as DATA.
     *
     * `buildSystemPrompt()` above resolves the archetype, the effective profile,
     * the locale name, the content-type label, the brand voice and the tenant's
     * prompt-override rows, then WRITES a prompt out of them. This method
     * resolves exactly the same things and stops there — phasea's
     * `RewriteSystemPromptComposer` does the writing (design §2.5).
     *
     * `buildSystemPrompt()` itself survives because the translate path still
     * calls it (D-48-4); it is not this plan's to remove.
     *
     * Two of these values are TENANT-authored instruction text
     * (`archetype_system_prompt`, each override's `override_content`). They
     * travel as parameters rather than moving into phasea's source because a
     * tenant writes and edits them through this host's admin UI — they are the
     * tenant's data, not this host's authored scaffolding. The composer's
     * docblock records the full reasoning; phasea bounds and validates both
     * before they reach an instruction string.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSystemPromptParameters(string $locale, array $context, ?Model $model = null): array
    {
        $archetype = $model ? $this->resolveArchetype($model) : null;

        // Send the archetype's OWN prompt only when the column actually holds
        // text; null otherwise. A seeded archetype now ships a null
        // `system_prompt` (48-16), so this is null for it — phasea then resolves
        // the DEFAULT from `archetype_slug` via `ContentArchetypeLibrary`.
        // `empty()` here mirrors the composer's `!empty()` absence check (so a
        // blank column, and the `'0'` edge, both send null), making the intent
        // explicit at the seam rather than relying on the far side to coerce an
        // empty string.
        $archetypePrompt = $archetype?->system_prompt;

        return [
            'archetype_system_prompt' => empty($archetypePrompt) ? null : $archetypePrompt,
            // The slug lets phasea pick the phasea-owned DEFAULT base when the
            // override above is null. Null when no archetype applies, in which
            // case phasea falls to the generic base.
            'archetype_slug' => $archetype?->slug,
            'profile' => $this->buildEffectiveProfile($archetype),
            'locale' => $locale,
            'locale_name' => $this->getLocaleName($locale),
            'content_type_label' => $this->getContentTypeLabel(),
            'brand_voice' => (string) config('ai.assistant.content.brand_voice', $this->getDefaultBrandVoice()),
            'context' => $context,
            'prompt_overrides' => $this->buildPromptOverrideParameters($this->getPromptOverrideKey()),
            // The host keeps DECIDING whether image markers apply — it depends
            // on this run's media settings — but no longer AUTHORS the block.
            'include_image_markers' => $this->imageMarkersEnabled(),
            'image_marker_variant' => $this->imageMarkerVariant(),
        ];
    }

    /**
     * The discriminator phasea's `rewrite.seo_field` template uses to select
     * which content type's instruction to render (`post`, `product`, …).
     *
     * NOT ABSTRACT, AND THE DEFAULT THROWS. Making it abstract would fatal every
     * subclass that has not been migrated yet, including
     * `WebinarPartContentRewriteService` and
     * `WebinarPartElementContentRewriteService`, which have NO SEO fields at all
     * (`getSeoFields()` returns `[]`) and would have to invent a discriminator
     * they never send. Returning a placeholder instead would be worse: phasea
     * would 422 the pair, or — if a placeholder ever matched a real entry —
     * render another content type's instruction over this one's data.
     *
     * So the base fails closed and LOUDLY, before any paid model call. The
     * `processModel()` loop catches it per field and records it as an error, the
     * same way any other SEO generation failure is recorded.
     *
     * Migrated in this plan: `post`, `product` (task 2), `product_category`,
     * `webinar` (task 3). Plan 48-07 finishes the two `WebinarPart*` subclasses,
     * which have no SEO fields, and removes their orphaned
     * `buildSeoFieldPrompt()` overrides.
     */
    protected function getSeoContentTypeKey(): string
    {
        throw new \LogicException(
            static::class.' has no SEO content type key: it has not been migrated to phasea\'s'
            .' rewrite.seo_field template (v2.6 design §2.5, phase 48).'
        );
    }

    /**
     * The v2.6 counterpart of `buildSeoSystemPrompt()` plus the SEO context: the
     * inputs of every SEO-shaped call this service makes, as DATA.
     *
     * THE SEO SURFACE HAS ITS OWN OVERRIDE KEY. `getSeoPromptOverrideKey()`
     * (`post_seo_system`) is a DIFFERENT key from `getPromptOverrideKey()`
     * (`post_rewrite_system`), so these are a different set of tenant rows from
     * the content half's — gathering the content rows here would apply the wrong
     * tenant instructions to every SEO field, silently.
     *
     * `buildSeoContext()` stays in this host because it reads host models, but
     * it hands over a STRUCTURED ARRAY: phasea JSON-encodes it into the
     * instruction with the host's original flags. A pre-rendered context string
     * would be host-authored prompt text wearing a parameter's name.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSeoPromptParameters(Model $model, string $locale, array $context): array
    {
        return [
            'locale' => $locale,
            'locale_name' => $this->getLocaleName($locale),
            'content_type_label' => $this->getContentTypeLabel(),
            'seo_context' => $this->buildSeoContext($model, $locale, $context),
            'prompt_overrides' => $this->buildPromptOverrideParameters($this->getSeoPromptOverrideKey()),
        ];
    }

    /**
     * The tenant's enabled `AIPromptOverride` rows for one prompt key, as data.
     *
     * THE KEY IS AN ARGUMENT, NOT A CONSTANT OF THIS SERVICE. There are two:
     * `getPromptOverrideKey()` for the content half and
     * `getSeoPromptOverrideKey()` for the SEO half. A second near-identical
     * method per key is how the two would eventually diverge, so callers name
     * the key they mean.
     *
     * ORDER IS LOAD-BEARING. `AIPromptOverride::applyOverrides()` applied them
     * with a plain foreach over a `created_at`-ordered query, so two overrides
     * that both append compose differently when swapped. `getOverridesFor()`
     * still supplies that order and it is preserved onto the wire; phasea
     * applies them in the order received.
     *
     * @return list<array{prompt_key: string, change_type: string, override_content: string}>
     */
    protected function buildPromptOverrideParameters(string $promptKey): array
    {
        $rows = [];

        foreach (AIPromptOverride::getOverridesFor($promptKey) as $override) {
            $rows[] = [
                'prompt_key' => $override->prompt_key,
                'change_type' => $override->change_type,
                'override_content' => $override->override_content,
            ];
        }

        return $rows;
    }

    /**
     * Whether this run's rich-text prompts should carry `[IMAGE:…]` markers.
     *
     * The DECISION stays here (it reads this run's media options); the
     * INSTRUCTION TEXT moved to phasea. `PostContentRewriteService` and
     * `ProductContentRewriteService` override this with the condition their
     * `getImageMarkerInstructions()` already used, so there is one source of
     * gating truth per subclass rather than two that can drift.
     */
    protected function imageMarkersEnabled(): bool
    {
        return false;
    }

    /**
     * Which phasea marker variant this content type renders, or null for the
     * four subclasses that inherit the empty base and never emitted markers.
     */
    protected function imageMarkerVariant(): ?string
    {
        return null;
    }

    /**
     * Build the effective content profile from archetype and global settings.
     *
     * Resolution: archetype override → global setting → default value
     *
     * @return array<string, mixed>
     */
    protected function buildEffectiveProfile(?Model $archetype): array
    {
        if ($archetype !== null) {
            return [
                'tone' => $archetype->getEffectiveTone(),
                'formality' => $archetype->getEffectiveFormality(),
                'personas' => $archetype->getEffectivePersonas(),
                'forbidden_words' => $archetype->getEffectiveForbiddenWords(),
                'mandatory_words' => $archetype->getEffectiveMandatoryWords(),
                'fixed_modules' => $archetype->getEffectiveFixedModules(),
                'style_rules' => $archetype->getEffectiveStyleRules(),
                'sections_config' => $archetype->sections_config ?? [],
            ];
        }

        // No archetype - use global settings only
        return [
            'tone' => config('system-settings.ai-store-tone', 'professional'),
            'formality' => config('system-settings.ai-store-formality', 'formal'),
            'personas' => $this->getJsonConfigArray('system-settings.ai-store-personas'),
            'forbidden_words' => $this->getJsonConfigArray('system-settings.ai-store-forbidden-words'),
            'mandatory_words' => $this->getJsonConfigArray('system-settings.ai-store-mandatory-words'),
            'fixed_modules' => $this->getJsonConfigArray('system-settings.ai-store-fixed-modules'),
            'style_rules' => $this->getJsonConfigArray('system-settings.ai-store-style-rules'),
            'sections_config' => [],
        ];
    }

    /**
     * Build system prompt using archetype's custom prompt.
     *
     * @param  array<string, mixed>  $profile
     */
    private function buildArchetypeSystemPrompt(
        Model $archetype,
        array $profile,
        string $localeName,
        string $locale,
        string $contextJson
    ): string {
        $archetypePrompt = $archetype->system_prompt;
        $profileInstructions = $this->buildProfileInstructions($profile);
        $humanWritingGuide = $this->getHumanWritingGuide();

        return <<<PROMPT
{$archetypePrompt}

WRITING PROFILE:
{$profileInstructions}

{$humanWritingGuide}

Target Language: {$localeName} ({$locale})

Context:
{$contextJson}

CRITICAL RULES:
- Write in {$localeName} ({$locale})
- Make content UNIQUE and different from any source material
- Never copy content directly - always rewrite in your own words

LANGUAGE HANDLING:
- PRESERVE intentional foreign words: brand names, technical terms, industry jargon
- Single English words mixed into {$localeName} text are usually intentional - DO NOT translate them
- Examples to KEEP unchanged: brand names, technical terms, proper nouns
PROMPT;
    }

    /**
     * Build default system prompt (no archetype).
     *
     * @param  array<string, mixed>  $profile
     */
    private function buildDefaultSystemPrompt(
        array $profile,
        string $localeName,
        string $locale,
        string $contentType,
        string $contextJson
    ): string {
        $brandVoice = config('ai.assistant.content.brand_voice', $this->getDefaultBrandVoice());
        $profileInstructions = $this->buildProfileInstructions($profile);
        $humanWritingGuide = $this->getHumanWritingGuide();

        return <<<PROMPT
You are an experienced content writer. Your task is to create unique, SEO-friendly {$contentType}.

Brand Voice: {$brandVoice}

WRITING PROFILE:
{$profileInstructions}

{$humanWritingGuide}

Target Language: {$localeName} ({$locale})

Context:
{$contextJson}

CRITICAL RULES:
- Write in {$localeName} ({$locale})
- Make content UNIQUE and different from any source material
- Never copy content directly - always rewrite in your own words

LANGUAGE HANDLING:
- PRESERVE intentional foreign words: brand names, technical terms, industry jargon
- Single English words mixed into {$localeName} text are usually intentional - DO NOT translate them
- Examples to KEEP unchanged: brand names, technical terms, proper nouns
PROMPT;
    }

    /**
     * Build profile instructions from effective profile settings.
     *
     * D-48-4 TRANSLATE-ONLY REMNANT — owned by phases 41–43. Reached only via
     * `buildArchetypeSystemPrompt()`/`buildDefaultSystemPrompt()` <-
     * `buildSystemPrompt()` <- `translateField()`. phasea's
     * `RewriteSystemPromptComposer` holds the live copy for the rewrite and
     * generate paths.
     *
     * FLAGGED FOR PLAN 48-12's GUARD: the fragments below ("FORBIDDEN WORDS
     * (never use these): …", "MANDATORY WORDS (include these naturally): …",
     * "Target Audience: …") are AUTHORED INSTRUCTION TEXT in the INTERPOLATED
     * SINGLE-LINE shape — no heredoc, no persona opener. That is precisely the
     * shape 48-CONTEXT records as having evaded three consecutive inventories, so
     * a line-based heredoc grep will not surface it. Enumerated deliberately.
     *
     * @param  array<string, mixed>  $profile
     */
    private function buildProfileInstructions(array $profile): string
    {
        $instructions = [];

        $instructions[] = "Tone: {$profile['tone']}";
        $instructions[] = "Formality: {$profile['formality']}";

        if (!empty($profile['personas'])) {
            $personas = implode(', ', $profile['personas']);
            $instructions[] = "Target Audience: {$personas}";
        }

        if (!empty($profile['forbidden_words'])) {
            $forbidden = implode(', ', $profile['forbidden_words']);
            $instructions[] = "FORBIDDEN WORDS (never use these): {$forbidden}";
        }

        if (!empty($profile['mandatory_words'])) {
            $mandatory = implode(', ', $profile['mandatory_words']);
            $instructions[] = "MANDATORY WORDS (include these naturally): {$mandatory}";
        }

        if (!empty($profile['style_rules'])) {
            foreach ($profile['style_rules'] as $rule => $value) {
                $instructions[] = "Style Rule - {$rule}: {$value}";
            }
        }

        return implode("\n", $instructions);
    }

    /**
     * Get writing style instructions that prevent AI-detectable patterns.
     */
    protected function getHumanWritingGuide(): string
    {
        return <<<'GUIDE'
WRITING STYLE — CRITICAL (your text must not be detectable as AI-generated):

DO:
- Vary sentence length dramatically — mix short punches with longer, detailed sentences
- Use contractions naturally (don't, it's, won't, that's)
- Use concrete numbers, specs, and real-world details instead of vague claims
- Vary paragraph length — some short, some longer
- Prefer active voice and strong verbs
- Get to the point — skip generic introductions

DO NOT — these are AI tells:
- NEVER use: "In today's [anything]", "When it comes to", "It's worth noting", "It's important to", "Let's dive in", "At the end of the day", "In conclusion"
- NEVER use filler adjectives: "robust", "seamless", "cutting-edge", "innovative", "comprehensive", "crucial", "vital", "landscape", "realm", "leverage", "utilize", "facilitate"
- NEVER use "Whether you're a [X] or a [Y]" constructions
- NEVER write a generic intro that restates the title or topic
- NEVER end with a generic summary repeating the main points
- NEVER use "Overall," or "In summary," to start closings
- NEVER have every paragraph be the same length
- NEVER start 3+ consecutive sentences with the same word
GUIDE;
    }

    /*
     * v2.6 design §2.5: `buildRewritePrompt()` was deleted here. Its wording is
     * phasea's `RewriteFieldPrompt` (`rewrite.field_rewrite`), which composes it
     * from the parameters `buildSystemPromptParameters()` now assembles. This
     * host holds no rewrite instruction text.
     */

    /**
     * Build prompt for translating content.
     */
    protected function buildTranslatePrompt(
        string $field,
        string $type,
        string $sourceLocale,
        string $targetLocale,
        string $sourceContent
    ): string {
        $fieldDescription = $this->getFieldDescription($field);
        $formatInstructions = $this->getFormatInstructions($type);
        $sourceLocaleName = $this->getLocaleName($sourceLocale);
        $targetLocaleName = $this->getLocaleName($targetLocale);

        return <<<PROMPT
Translate and REWRITE the following {$fieldDescription} from {$sourceLocaleName} to {$targetLocaleName}.
Do not just translate word-for-word - adapt it naturally for {$targetLocaleName} speakers and make it unique.
The result must read like it was originally written in {$targetLocaleName} by a native speaker.

Source content ({$sourceLocaleName}):
{$sourceContent}

Requirements:
- Write in natural {$targetLocaleName}
- {$fieldDescription}
{$formatInstructions}
- Adapt cultural references and expressions appropriately
- Make it sound native, not translated
- Make it UNIQUE - not a direct translation
- Vary sentence length and structure — don't mirror the source sentence-by-sentence
- No filler adjectives (robust, seamless, cutting-edge, innovative, comprehensive)

Return ONLY the translated/rewritten content, no explanations.
PROMPT;
    }

    /*
     * v2.6 design §2.5: `buildGeneratePrompt()` was deleted here. Its wording is
     * phasea's `GenerateFieldPrompt` (`rewrite.field_generate`).
     */

    // ═══════════════════════════════════════════════════════════════════════
    // AI INTERACTION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Call AI provider and process response.
     *
     * WR-02 (Phase 16-05, closes the Phase 13 deferred item): routed through
     * `completeText()` instead of `rewriteContent()` so the FULL system
     * prompt (AIPromptOverrides, brand/model context, format rules) reaches
     * phasea — `rewriteContent()` flattened it onto `style`, which phasea's
     * `RewriteController` silently discarded (13-REVIEW WR-02,
     * docs/ai-contract.md §5). Per-call-site `AiSurface` tagging drives
     * phasea's usage metering by surface (T-16-05-03): `rewriteField()`,
     * `generateField()`, `generateSeoField()`, `generateFocusKeyphrase()`, and
     * `enhanceContentWithUniqueSections()` tag `Rewrite`; `translateField()`,
     * `translateText()`, and `translateRichChunk()` tag `Translate`.
     *
     * `$surface` HAS NO DEFAULT (plan 48-20, guard weakness W4). It used to
     * default to `AiSurface::Rewrite`, so a new caller that simply omitted the
     * argument got a non-translate `completeText()` call silently — the exact
     * hole `NoNonTranslateCompleteTextCallerTest` exists to close. Every rewrite
     * call site migrated to `prompt/run` in waves 48-05..48-07, so the three
     * surviving callers all tag `Translate` (D-48-4, phases 41–43) and an
     * omitted argument is now a fatal, not a silent reintroduction.
     *
     * @param  (Model&Translatable)|null  $model
     */
    protected function callAI(
        string $systemPrompt,
        string $userPrompt,
        string $type,
        ?Model $model,
        ?string $field,
        ?string $locale,
        AiSurface $surface,
    ): string {
        $this->guardCallBudget();

        // operationId: caller-supplied (job property, threaded via setOperationId())
        // or minted per-invocation when unset (SEAM-08 operationId threading, plan 08-15).
        $operationId = $this->operationId ?? (string) Str::uuid();

        $this->logDebug($this->getServiceName().' - Calling AI', [
            'operation_id' => $operationId,
            'system_prompt_length' => strlen($systemPrompt),
            'user_prompt_length' => strlen($userPrompt),
            'type' => $type,
            'surface' => $surface->value,
        ]);

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for($surface);

        try {
            // Per-feature timeout budget (audit 5.1): translation 240s /
            // content_rewrite 600s via resolveTimeout() — honored by
            // RemoteAiClient (clamped to `ai.timeout_max`) instead of the
            // global 30s default that failed every long generation.
            $result = $client->completeText(
                $systemPrompt,
                $userPrompt,
                ['timeout' => $this->resolveTimeout()],
                $surface,
                $operationId,
            );
            $rewritten = $result->text;

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logLLMCommunication(
                $this->getServiceName(),
                $systemPrompt,
                [['role' => 'user', 'content' => $userPrompt]],
                $rewritten,
                ['prompt_tokens' => $result->promptTokens, 'completion_tokens' => $result->completionTokens],
                0.0
            );

            $this->logDebug($this->getServiceName().' - AI Response', [
                'operation_id' => $operationId,
                'response_length' => strlen($rewritten),
                'duration_ms' => $duration,
            ]);

            $rewritten = $this->stripCodeBlockWrappers($rewritten);

            if ($type === 'rich') {
                $rewritten = MarkdownToHtmlConverter::toHtml($rewritten);
            }

            return $rewritten;
        } catch (\Exception $e) {
            $this->logError($this->getServiceName().' - AI Call Failed', $e);
            throw $e;
        }
    }

    /**
     * Run a PHASEA-OWNED prompt by template key (v2.6 design §2.5).
     *
     * The `callAI()` twin for every call site that used to assemble instruction
     * text here. Deliberately identical to it in everything except WHAT is sent:
     * the same `guardCallBudget()` ceiling, the same operation-id resolution
     * (caller-threaded or per-invocation UUID), the same
     * `AiClientFactory::for($surface)` resolution, the same
     * `resolveTimeout()` budget, the same `stripCodeBlockWrappers()` and
     * rich-text `MarkdownToHtmlConverter` post-processing, and the same
     * log-and-rethrow on failure.
     *
     * THE TIMEOUT IS THE EASIEST THING TO LOSE HERE. `runPrompt()`'s `$options`
     * is transport-only and is never serialised into the request body, so a
     * dropped `timeout` is invisible in every request assertion — the rewrite
     * surface would silently fall back to the 30s `AI_SERVICE_TIMEOUT` default
     * instead of its 600s `ai.assistant.timeouts.content_rewrite` budget, and
     * only long generations would die. `ContentRewritePromptOwnershipTest` pins
     * it through the resolved transfer options rather than by inspection.
     *
     * D-48-6 — AUDIT CONTINUITY. The LLM communication log recorded the prompt
     * strings; there are none now, so it records `{template, parameters}` — the
     * reproducible INPUT, which is what made the old entry useful. The debug
     * context swaps `system_prompt_length`/`user_prompt_length` for `template`
     * and `parameter_count` for the same reason.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function callPromptTemplate(
        string $template,
        array $parameters,
        string $type,
        AiSurface $surface = AiSurface::Rewrite,
    ): string {
        $this->guardCallBudget();

        $operationId = $this->operationId ?? (string) Str::uuid();

        $this->logDebug($this->getServiceName().' - Calling AI', [
            'operation_id' => $operationId,
            'template' => $template,
            'parameter_count' => count($parameters),
            'type' => $type,
            'surface' => $surface->value,
        ]);

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for($surface);

        try {
            $result = $client->runPrompt(
                $template,
                $parameters,
                $operationId,
                ['timeout' => $this->resolveTimeout()],
            );
            $rewritten = $result->text;

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logLLMCommunication(
                $this->getServiceName(),
                $template,
                [['role' => 'parameters', 'content' => json_encode($parameters, JSON_UNESCAPED_UNICODE) ?: '']],
                $rewritten,
                ['prompt_tokens' => $result->promptTokens, 'completion_tokens' => $result->completionTokens],
                0.0
            );

            $this->logDebug($this->getServiceName().' - AI Response', [
                'operation_id' => $operationId,
                'response_length' => strlen($rewritten),
                'duration_ms' => $duration,
            ]);

            $rewritten = $this->stripCodeBlockWrappers($rewritten);

            if ($type === 'rich') {
                $rewritten = MarkdownToHtmlConverter::toHtml($rewritten);
            }

            return $rewritten;
        } catch (\Exception $e) {
            $this->logError($this->getServiceName().' - AI Call Failed', $e);
            throw $e;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PERSISTENCE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save translation to database.
     *
     * @param  Model&Translatable  $model
     */
    protected function saveTranslation(Model $model, string $field, string $locale, string $content): void
    {
        /** @phpstan-ignore property.notFound */
        $modelId = (int) $model->id;

        $this->logDebug($this->getServiceName().' - Saving Translation', [
            'model_id' => $modelId,
            'field' => $field,
            'locale' => $locale,
            'content_length' => strlen($content),
        ]);

        $translation = $model->translateOrNew($locale);
        $translation->{$field} = $content;
        $translation->save();

        if (in_array($model::class, $this->getTouchableModelClasses(), true)) {
            $model->touch();
        }

        $this->logDebug($this->getServiceName().' - Translation Saved', [
            'model_id' => $modelId,
            'field' => $field,
            'locale' => $locale,
        ]);
    }

    /**
     * Reconcile a field whose translation phasea reports as ALREADY COMPLETED.
     *
     * A queued translation job retries a whole content object after a transient provider
     * blip; the fields that already succeeded on the earlier attempt carry stable
     * deterministic ids, so their re-send is answered `409 operation_already_completed`
     * (never re-run, never re-billed). That is a SUCCESS for the field, not a failure — but
     * only if the earlier attempt's translated text was actually persisted here. It normally
     * was (phasea metered it BECAUSE it returned it and the host saved it), so the common
     * path records the field as processed and the user sees a clean completion.
     *
     * The one case it must NOT hide: phasea metered the result but its response never reached
     * the host (a drop after billing), so nothing is persisted. The text cannot be recovered
     * under the same id, so that stays a real error the user must see — a corrected retry needs
     * a fresh operation id.
     *
     * @param  Model&Translatable  $model
     * @param  array{model_id: int, processed_fields: array<string>, errors: array<string>}  $results
     */
    protected function reconcileAlreadyCompletedField(
        Model $model,
        string $field,
        string $targetLocale,
        array &$results,
        AiOperationAlreadyCompletedException $e,
    ): void {
        $targetTranslation = $model->translate($targetLocale);

        if ($targetTranslation !== null && !empty($targetTranslation->{$field})) {
            $results['processed_fields'][] = "{$field}:{$targetLocale}";

            $this->logDebug($this->getServiceName().' - Field already completed on a prior attempt; counted as processed', [
                'field' => $field,
                'target_locale' => $targetLocale,
            ]);

            return;
        }

        // Metered upstream but never persisted here — unrecoverable under this id.
        $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
        $this->logError($this->getServiceName().' - Field reported already completed but no local translation exists', $e);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Per-job AI call ceiling — prevents a single runaway job from making
     * an unbounded number of provider requests.
     */
    protected function guardCallBudget(): void
    {
        $this->aiCallCount++;
        $max = (int) config('ai.assistant.circuit_breaker.max_ai_calls_per_job', 150);

        if ($this->aiCallCount > $max) {
            throw new \RuntimeException("AI call budget exceeded: {$this->aiCallCount}/{$max} calls in a single job.");
        }
    }

    /**
     * Get configured locales.
     *
     * @return array<string>
     */
    protected function getLocales(): array
    {
        return config('translatable.locales', ['en']);
    }

    /**
     * Get default brand voice for prompts.
     */
    protected function getDefaultBrandVoice(): string
    {
        return 'Professional content';
    }

    /*
     * v2.6: resolveReasoningEffort() was removed. It had ZERO callers (its
     * sibling copy in AIAssistantService went in Plan 16-07) and it decided a
     * provider-shaped behaviour from config('ai.assistant.translation.provider')
     * — a provider the host no longer owns, since phasea routes the translate
     * task.
     *
     * Superseded further: callers used to send `reasoning_effort` as a TASK
     * KEY ('blog_writing', 'internal_linking', …) so phasea could resolve an
     * effort level. That was still the host naming an AI-behavioral field on
     * every request — `surface` already tells phasea which task this is.
     * `reasoning_effort` is no longer sent at all (removed from every
     * completeText() call site); RemoteAiClient::completeText() also strips
     * it defensively if a caller ever reintroduces it. See the
     * AiClient::completeText() docblock.
     */

    protected function resolveTimeout(): int
    {
        $key = $this->useTranslationProvider ? 'translation' : 'content_rewrite';

        return (int) config("ai.assistant.timeouts.$key", config('ai.assistant.timeout', 300));
    }

    /**
     * Get human-readable locale name.
     */
    protected function getLocaleName(string $locale): string
    {
        /** @var array<string, array{name?: string}> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        return $supportedLocales[$locale]['name'] ?? $locale;
    }

    /**
     * Get format instructions for plain or rich text.
     *
     * D-48-4 — TRANSLATE ONLY as of plan 48-05. `buildTranslatePrompt()` is its
     * one remaining caller. phasea's `RewriteFormatInstructions` holds the same
     * text for the three migrated paths; the duplication is deliberate and ends
     * when phases 41–43 migrate translation.
     */
    protected function getFormatInstructions(string $type): string
    {
        if ($type === 'plain') {
            return '- Return PLAIN TEXT only. NO HTML tags. NO markdown syntax. NO code blocks.';
        }

        $instructions = '- Use HTML formatting: <p>, <ul>, <li>, <strong>, <em>, <h2>, <h3>. NEVER use markdown. NEVER wrap output in code blocks (no ```html or ``` markers).';

        $markerInstructions = $this->getImageMarkerInstructions();
        if ($markerInstructions !== '') {
            $instructions .= "\n".$markerInstructions;
        }

        return $instructions;
    }

    /**
     * Truncate content for use in prompts.
     */
    protected function truncateForContext(?string $content, int $maxLength = 500): ?string
    {
        if (!$content) {
            return null;
        }

        $plainText = strip_tags($content);
        if (strlen($plainText) <= $maxLength) {
            return $plainText;
        }

        return Str::limit($plainText, $maxLength);
    }

    /**
     * Remove markdown code block wrappers from AI output.
     */
    protected function stripCodeBlockWrappers(string $content): string
    {
        $content = preg_replace('/^```[a-zA-Z]*\s*\n?/m', '', $content);
        $content = preg_replace('/\n?```\s*$/m', '', $content);

        return trim($content);
    }

    /**
     * Generate unique slug for a model.
     */
    protected function generateUniqueSlug(string $baseName, int $excludeId, string $locale): string
    {
        $baseSlug = app(GeneratesSlugs::class)->make($baseName);
        $slug = $baseSlug;
        $counter = 1;

        while ($this->slugExistsForLocale($slug, $excludeId, $locale)) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Sanitize exception message for logging.
     *
     * Removes HTML content and truncates long messages while preserving
     * the meaningful error information (status codes, error types).
     */
    protected function sanitizeErrorMessage(\Exception $e, int $maxLength = 500): string
    {
        $message = $e->getMessage();

        // If message contains HTML (common in HTTP error responses), extract just the text before it
        if (str_contains($message, '<html') || str_contains($message, '<HTML') || str_contains($message, '<!DOCTYPE')) {
            $originalMessage = $message;
            // Extract everything before the HTML starts
            $htmlPos = min(
                stripos($message, '<html') ?: PHP_INT_MAX,
                stripos($message, '<!DOCTYPE') ?: PHP_INT_MAX
            );
            if ($htmlPos !== PHP_INT_MAX) {
                $message = trim(substr($message, 0, $htmlPos));
            }
            // If nothing useful was extracted, try to get status code from original
            if ($message === '') {
                if (preg_match('/status code (\d{3})/', $originalMessage, $matches)) {
                    $message = "HTTP error {$matches[1]}";
                } else {
                    $message = 'HTTP error (HTML response body omitted)';
                }
            }
        }

        // Truncate if still too long
        if (strlen($message) > $maxLength) {
            $message = substr($message, 0, $maxLength - 3).'...';
        }

        return $message;
    }

    /**
     * Get a config value that may be stored as JSON string and decode it to array.
     * The system-settings-db package stores JSON values as strings in the database.
     *
     * @return array<mixed>
     */
    protected function getJsonConfigArray(string $key): array
    {
        $value = config($key);

        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
