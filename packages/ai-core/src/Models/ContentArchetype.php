<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\ContentArchetypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $applicable_model
 * @property string|null $description
 * @property string $system_prompt
 * @property array<string, mixed> $sections_config
 * @property string|null $tone
 * @property string|null $formality
 * @property array<int, string>|null $forbidden_words
 * @property array<int, string>|null $mandatory_words
 * @property array<string, mixed>|null $style_rules
 * @property bool $is_default
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentArchetype extends Model
{
    /** @use HasFactory<ContentArchetypeFactory> */
    use HasFactory;

    /**
     * Package-local factory resolution: Laravel's default convention looks in
     * the consumer app's `Database\Factories` namespace first; point it back
     * at the package factory explicitly.
     */
    protected static function newFactory(): ContentArchetypeFactory
    {
        return ContentArchetypeFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'applicable_model',
        'description',
        'system_prompt',
        'sections_config',
        'tone',
        'formality',
        'forbidden_words',
        'mandatory_words',
        'style_rules',
        'is_default',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sections_config' => 'array',
            'forbidden_words' => 'array',
            'mandatory_words' => 'array',
            'style_rules' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ContentArchetype $archetype): void {
            if (empty($archetype->slug)) {
                $archetype->slug = self::generateSlug($archetype->name);
            }
        });

        static::saving(function (ContentArchetype $archetype): void {
            if ($archetype->is_default && $archetype->isDirty('is_default')) {
                static::where('applicable_model', $archetype->applicable_model)
                    ->where('id', '!=', $archetype->id ?? 0)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Generate a URL slug, falling back to a Unicode-preserving slug for
     * scripts that transliterate to nothing (CJK, Thai, Hebrew, ...).
     */
    private static function generateSlug(string $title): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : Str::slug($title, '-', null);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EFFECTIVE VALUES (archetype override or global fallback)
    // ═══════════════════════════════════════════════════════════════════════

    public function getEffectiveTone(): string
    {
        return $this->tone ?? config('system-settings.ai-store-tone', 'professional');
    }

    public function getEffectiveFormality(): string
    {
        return $this->formality ?? config('system-settings.ai-store-formality', 'formal');
    }

    /**
     * @return array<int, string>
     */
    public function getEffectiveForbiddenWords(): array
    {
        return array_merge(
            $this->getJsonConfigArray('system-settings.ai-store-forbidden-words'),
            $this->forbidden_words ?? []
        );
    }

    /**
     * @return array<int, string>
     */
    public function getEffectiveMandatoryWords(): array
    {
        return array_merge(
            $this->getJsonConfigArray('system-settings.ai-store-mandatory-words'),
            $this->mandatory_words ?? []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getEffectiveStyleRules(): array
    {
        return array_merge(
            $this->getJsonConfigArray('system-settings.ai-store-style-rules'),
            $this->style_rules ?? []
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getEffectiveFixedModules(): array
    {
        return $this->getJsonConfigArray('system-settings.ai-store-fixed-modules');
    }

    /**
     * @return array<int, string>
     */
    public function getEffectivePersonas(): array
    {
        return $this->getJsonConfigArray('system-settings.ai-store-personas');
    }

    /**
     * Get a config value that may be stored as a JSON string and decode it to
     * an array. Some config sources store JSON values as strings.
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

    // ═══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  Builder<ContentArchetype>  $query
     * @param  class-string  $modelClass
     * @return Builder<ContentArchetype>
     */
    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('applicable_model', class_basename($modelClass))
            ->where('active', true);
    }

    /**
     * @param  Builder<ContentArchetype>  $query
     * @return Builder<ContentArchetype>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<ContentArchetype>  $query
     * @return Builder<ContentArchetype>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the default archetype for a given model type.
     *
     * @param  class-string  $modelClass
     */
    public static function getDefaultForModel(string $modelClass): ?self
    {
        return static::forModel($modelClass)
            ->default()
            ->first();
    }
}
