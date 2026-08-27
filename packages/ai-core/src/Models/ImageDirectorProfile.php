<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\ImageDirectorProfileFactory;
use Core45\AiCore\ModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $applicable_model
 * @property string|null $description
 * @property string|null $system_prompt
 * @property string|null $style_guidance
 * @property array<int, string>|null $forbidden
 * @property array<int, string>|null $always_include
 * @property string|null $negative_prompt
 * @property int|null $default_image_style_id
 * @property bool $is_default
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ImageStyle|null $defaultImageStyle
 */
class ImageDirectorProfile extends Model
{
    /** @use HasFactory<ImageDirectorProfileFactory> */
    use HasFactory;

    /**
     * Package-local factory resolution: Laravel's default convention looks in
     * the consumer app's `Database\Factories` namespace first; point it back
     * at the package factory explicitly.
     */
    protected static function newFactory(): ImageDirectorProfileFactory
    {
        return ImageDirectorProfileFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'applicable_model',
        'description',
        'system_prompt',
        'style_guidance',
        'forbidden',
        'always_include',
        'negative_prompt',
        'default_image_style_id',
        'is_default',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forbidden' => 'array',
            'always_include' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ImageDirectorProfile $profile): void {
            if (empty($profile->slug)) {
                $profile->slug = self::generateSlug($profile->name);
            }
        });

        static::saving(function (ImageDirectorProfile $profile): void {
            if ($profile->is_default && ($profile->isDirty('is_default') || $profile->isDirty('applicable_model'))) {
                static::query()
                    ->where('id', '!=', $profile->id ?? 0)
                    ->when(
                        $profile->applicable_model === null,
                        fn (Builder $q) => $q->whereNull('applicable_model'),
                        fn (Builder $q) => $q->where('applicable_model', $profile->applicable_model),
                    )
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
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return BelongsTo<ImageStyle, $this>
     */
    public function defaultImageStyle(): BelongsTo
    {
        return $this->belongsTo(ImageStyle::class, 'default_image_style_id');
    }

    /**
     * Posts using this profile directly.
     *
     * Resolved via the host's {@see ModelRegistry} under the 'post' key
     * rather than a literal FQCN, so this package carries no host-namespace
     * dependency.
     *
     * @return HasMany<Model, $this>
     */
    public function posts(): HasMany
    {
        // getModelClassOrFail() is the class-string-typed accessor (see
        // Support\HostFactory for the same convention); getModelClass()
        // returns a plain nullable string and loses that guarantee for
        // hasMany() below.
        $postClass = app(ModelRegistry::class)->getModelClassOrFail('post');

        return $this->hasMany($postClass, 'image_director_profile_id');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  Builder<ImageDirectorProfile>  $query
     * @return Builder<ImageDirectorProfile>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<ImageDirectorProfile>  $query
     * @return Builder<ImageDirectorProfile>
     */
    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('applicable_model');
    }

    /**
     * @param  Builder<ImageDirectorProfile>  $query
     * @param  class-string  $modelClass
     * @return Builder<ImageDirectorProfile>
     */
    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('applicable_model', class_basename($modelClass));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the default profile for a given model class, falling back to the
     * general default when no scoped default exists.
     *
     * @param  class-string|null  $modelClass
     */
    public static function defaultForModel(?string $modelClass): ?self
    {
        $scoped = $modelClass
            ? static::active()->forModel($modelClass)->where('is_default', true)->first()
            : null;

        return $scoped ?? static::active()->general()->where('is_default', true)->first();
    }
}
