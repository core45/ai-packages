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
    // outside the boot window.
    //
    // `AiCoreServiceProvider::registerAiDetectionObserver()`, called from that
    // provider's `boot()`, attaches it instead — to every model in
    // `config('ai.models')` that both extends Model and uses `HasAiDetection`,
    // and only when `ai.assistant.ai_detection.enabled` is on.
    //
    // That sentence was here before the code was. Nothing in the provider
    // called `observe()`, so the packaged observer was dead in every install;
    // the reference host did not notice because its own models carry
    // `#[ObservedBy(App\Observers\AiDetectionObserver::class)]` and ran a host
    // copy of it. If this comment and the provider ever disagree again, the
    // provider is the one telling the truth.
}
