<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Observers\AiDetectionObserver;

/**
 * Umbrella trait for host content models (e.g. `Post`) that need the full
 * set of ai-core AI-content traits. Composes the seven package traits a
 * host model would otherwise have to `use` individually, and registers the
 * AI-detection observer that the host previously declared explicitly via
 * `#[ObservedBy(AiDetectionObserver::class)]`.
 *
 * A host model still implements the abstract methods required by the
 * composed traits (e.g. `geoProfileData()`, `getTranslatableFieldsForSnapshot()`,
 * `getEmbeddingText()`), and still needs its own `HasAiDetection` trait for
 * AI-detection relations/helpers — this trait only wires the observer.
 */
trait HasAiContent
{
    use AIQueryableTrait;
    use HasContentArchetype;
    use HasGeoProfile;
    use HasImageDirectorProfile;
    use HasSearchableEmbedding;
    use HasSeoIndexable;
    use HasTranslationLocks;

    // The AI-detection observer is NOT registered here. `Model::observe()`
    // does `new static`, which re-enters `bootIfNotBooted()` on the model that
    // is *currently* booting and throws a LogicException. The host's former
    // `#[ObservedBy]` attribute avoided this because Laravel resolves it
    // outside the boot window. AiCoreServiceProvider::boot() now registers the
    // observer for every model in the ModelRegistry instead.
}
