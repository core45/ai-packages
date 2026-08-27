<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\ImageDirectorProfile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that can have an AI image director profile assigned.
 *
 * Provides profile relationship and resolution logic:
 * 1. Direct assignment on the model
 * 2. System default for the model type
 *
 * @property int|null $image_director_profile_id
 * @property-read ImageDirectorProfile|null $imageDirectorProfile
 */
trait HasImageDirectorProfile
{
    /**
     * Get the image director profile relationship.
     *
     * @return BelongsTo<ImageDirectorProfile, $this>
     */
    public function imageDirectorProfile(): BelongsTo
    {
        return $this->belongsTo(ImageDirectorProfile::class, 'image_director_profile_id');
    }

    /**
     * Resolve the effective image director profile for this model.
     *
     * Resolution order:
     * 1. Direct assignment on the model
     * 2. System default for this model type
     */
    public function resolveImageDirectorProfile(): ?ImageDirectorProfile
    {
        return $this->imageDirectorProfile ?? ImageDirectorProfile::defaultForModel(static::class);
    }
}
