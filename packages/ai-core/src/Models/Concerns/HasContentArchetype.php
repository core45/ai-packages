<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\ContentArchetype;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that can have a content archetype assigned.
 *
 * Provides archetype relationship and resolution logic:
 * 1. Direct assignment on the model
 * 2. Category default (if the model exposes a `categories()` relation)
 * 3. System default for the model type
 *
 * @property int|null $content_archetype_id
 * @property-read ContentArchetype|null $contentArchetype
 */
trait HasContentArchetype
{
    /**
     * Get the content archetype relationship.
     *
     * @return BelongsTo<ContentArchetype, $this>
     */
    public function contentArchetype(): BelongsTo
    {
        return $this->belongsTo(ContentArchetype::class, 'content_archetype_id');
    }

    /**
     * Resolve the effective content archetype for this model.
     *
     * Resolution order:
     * 1. Direct assignment
     * 2. Category default (for models with a `categories()` relation)
     * 3. System default for this model type
     */
    public function resolveContentArchetype(): ?ContentArchetype
    {
        if ($this->contentArchetype !== null) {
            return $this->contentArchetype;
        }

        $categoryArchetype = $this->resolveArchetypeFromCategory();
        if ($categoryArchetype !== null) {
            return $categoryArchetype;
        }

        return ContentArchetype::getDefaultForModel(static::class);
    }

    /**
     * Try to resolve the archetype from the first related category's default,
     * when the host model exposes a `categories()` relation.
     */
    private function resolveArchetypeFromCategory(): ?ContentArchetype
    {
        if (!method_exists($this, 'categories')) {
            return null;
        }

        $category = $this->categories()->first();

        if ($category === null || !method_exists($category, 'defaultContentArchetype')) {
            return null;
        }

        return $category->defaultContentArchetype;
    }

    /**
     * Check if this model has an archetype assigned (directly or via category/default).
     */
    public function hasContentArchetype(): bool
    {
        return $this->resolveContentArchetype() !== null;
    }

    /**
     * Get the archetype name for display purposes.
     */
    public function getContentArchetypeName(): ?string
    {
        return $this->resolveContentArchetype()?->name;
    }

    /**
     * Get the source of the resolved archetype.
     *
     * @return string|null 'direct', 'category', 'default', or null if no archetype
     */
    public function getContentArchetypeSource(): ?string
    {
        if ($this->contentArchetype !== null) {
            return 'direct';
        }

        if ($this->resolveArchetypeFromCategory() !== null) {
            return 'category';
        }

        if (ContentArchetype::getDefaultForModel(static::class) !== null) {
            return 'default';
        }

        return null;
    }
}
