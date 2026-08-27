<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\ImageStyleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $prompt_fragment
 * @property int $sort_order
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImageStyle extends Model
{
    /** @use HasFactory<ImageStyleFactory> */
    use HasFactory;

    /**
     * Package-local factory resolution: Laravel's default convention looks in
     * the consumer app's `Database\Factories` namespace first; point it back
     * at the package factory explicitly.
     */
    protected static function newFactory(): ImageStyleFactory
    {
        return ImageStyleFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'prompt_fragment',
        'sort_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ImageStyle $imageStyle): void {
            if (empty($imageStyle->slug)) {
                $imageStyle->slug = self::generateSlug($imageStyle->name);
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

    /**
     * @param  Builder<ImageStyle>  $query
     * @return Builder<ImageStyle>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Get active image styles as a slug => name map for selects.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::active()->orderBy('sort_order')->pluck('name', 'slug')->all();
    }

    /**
     * Find an image style by slug, or return null.
     */
    public static function resolveBySlug(?string $slug): ?self
    {
        return $slug ? static::where('slug', $slug)->first() : null;
    }
}
