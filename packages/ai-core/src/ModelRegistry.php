<?php

declare(strict_types=1);

namespace Core45\AiCore;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Contracts\AIQueryable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Registry of AI-capable models (D1 widened, A4 — consumer-supplied
 * registrations): the package owns the LOOKUP LOGIC while the CONSUMER
 * supplies the model map at boot. The host binds this as a scoped singleton
 * with its own models; domain packages may bind their own or extend the host's.
 *
 * The host keeps a thin static delegator (`App\Services\AI\ModelRegistry`) so
 * existing static call sites remain source-compatible during extraction.
 */
class ModelRegistry
{
    private const SCHEMA_CACHE_KEY = 'ai_assistant_schema';

    /**
     * @param  array<string, class-string<Model>>  $models  key => model class
     * @param  array<string, string>  $seoContentTypes  model class => phasea SEO content_type slug
     */
    public function __construct(
        private readonly array $models = [],
        private readonly array $urlSlugs = [],
        private readonly array $seoContentTypes = [],
    ) {}

    /**
     * Get all registered AI-capable models.
     *
     * @return array<string, class-string<Model>>
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * Get models that implement AIContentable.
     *
     * @return array<string, class-string<Model&AIContentable>>
     */
    public function getContentableModels(): array
    {
        return array_filter(
            $this->models,
            fn ($class) => in_array(AIContentable::class, $this->interfacesOf($class), true)
        );
    }

    /**
     * Get models that implement AIQueryable.
     *
     * @return array<string, class-string<Model&AIQueryable>>
     */
    public function getQueryableModels(): array
    {
        return array_filter(
            $this->models,
            fn ($class) => in_array(AIQueryable::class, $this->interfacesOf($class), true)
        );
    }

    /**
     * Interfaces implemented by a configured class.
     *
     * §7: `class_implements()` returns FALSE, not an array, when the class does
     * not exist. Passing that straight to `in_array()` raises a TypeError from
     * inside `boot()` — breaking every request — with a message that never names
     * the bad `config('ai.models')` entry. Name it.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException
     */
    private function interfacesOf(string $class): array
    {
        $interfaces = class_exists($class) ? class_implements($class) : false;

        if ($interfaces === false) {
            throw new \InvalidArgumentException(sprintf(
                'config(\'ai.models\') maps a key to "%s", which does not exist. Fix or remove that entry.',
                $class,
            ));
        }

        return $interfaces;
    }

    /**
     * Get model class by key.
     */
    public function getModelClass(string $key): ?string
    {
        return $this->models[$key] ?? null;
    }

    /**
     * Get model class by key, or throw naming the key.
     *
     * Package code should prefer this over `getModelClass()`. §3: a registry key
     * read by package code but absent from the host's `config('ai.models')`
     * returns null and then fatals somewhere downstream — a `null::query()` or a
     * query against the wrong table — at a call site that never names the key
     * that was actually missing. Failing here instead turns a confusing runtime
     * fault into a message that says exactly which key to add.
     *
     * Never fall back to `Model::class` or to a guessed class name. A silent
     * fallback queries the wrong table and reads as a data bug months later.
     *
     * @return class-string<Model>
     *
     * @throws \InvalidArgumentException
     */
    public function getModelClassOrFail(string $key): string
    {
        $class = $this->models[$key] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException(sprintf(
                'No model registered for key "%s". Add it to config(\'ai.models\'). Registered keys: %s',
                $key,
                $this->models === [] ? '(none)' : implode(', ', array_keys($this->models)),
            ));
        }

        return $class;
    }

    /**
     * Get key for a model class.
     */
    public function getModelKey(string $class): ?string
    {
        return array_flip($this->models)[$class] ?? null;
    }

    /**
     * Map a host model class onto phasea's neutral model_type slug for chat
     * payloads. Returns null when the class is not registered.
     */
    public function getNeutralSlug(string $modelClass): ?string
    {
        return $this->getModelKey($modelClass);
    }

    /**
     * Map a host model class onto phasea's SEO `content_type` enum value
     * (`in:product,post,category,webinar`). Returns null when unmapped.
     */
    public function getSeoContentType(string $modelClass): ?string
    {
        return $this->seoContentTypes[$modelClass] ?? null;
    }

    /**
     * Get schema information for AI context.
     * Results are cached for performance (schema rarely changes).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSchemaForAI(): array
    {
        return Cache::remember(
            self::SCHEMA_CACHE_KEY,
            now()->addHours(24),
            function () {
                return $this->buildSchema();
            }
        );
    }

    /**
     * Build the schema array (internal, uncached).
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSchema(): array
    {
        $schema = [];

        foreach ($this->getQueryableModels() as $key => $class) {
            $instance = new $class;
            $schema[$key] = [
                'label' => $class::getAIModelLabel(),
                'plural_label' => $class::getAIModelPluralLabel(),
                'queryable_fields' => $instance->getAIQueryableFields(),
                'filter_scopes' => $instance->getAIFilterScopes(),
                'actions' => $instance->getAIActions(),
            ];
        }

        return $schema;
    }

    /**
     * Clear the cached AI schema.
     * Call this after modifying model AI configurations.
     */
    public function clearSchemaCache(): void
    {
        Cache::forget(self::SCHEMA_CACHE_KEY);
    }

    /**
     * Refresh the cached AI schema (clear and rebuild).
     *
     * @return array<string, array<string, mixed>>
     */
    public function refreshSchemaCache(): array
    {
        $this->clearSchemaCache();

        return $this->getSchemaForAI();
    }

    /**
     * Get mapping of Filament URL slugs to model class names.
     * Used by the AI chat panel to auto-detect context from URL.
     *
     * @return array<string, string> slug => model class
     */
    public function getUrlSlugMapping(): array
    {
        return $this->urlSlugs;
    }

    /**
     * Get model class from a Filament URL slug.
     */
    public function getModelClassFromSlug(string $slug): ?string
    {
        return $this->urlSlugs[$slug] ?? null;
    }
}
