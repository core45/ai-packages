<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Observability;

use Core45\AiCore\Contracts\ObservesAiContracts;

/**
 * The default when the host has not bound its own contract observer.
 *
 * `ObservesAiContracts` is documented as "used only for exit-window
 * observability, never to branch a render", and its `observeContract()`
 * contract already requires implementations to "guard every failure mode
 * (probe, cache, log) themselves" so the call MUST NOT THROW INTO A RENDER
 * PATH. A no-op is not a stub standing in for missing work here — it is the
 * strictest possible reading of that requirement: nothing to probe, nothing
 * to cache, nothing to log, so nothing happens.
 *
 * No call site for this contract exists inside ai-core: it is resolved
 * unconditionally (no `bound()` guard, no try/catch) from
 * `ai-posts/src/Services/BlogPipeline/Modules/DynamicContentModule.php` on
 * every module render. Because `AiCoreServiceProvider` is registered once for
 * the whole application container, binding the default here fixes the
 * fresh-install `BindingResolutionException` for that call site too, without
 * touching ai-posts.
 *
 * `bindIf`: a host with its own observer keeps it.
 */
final class NullContractObserver implements ObservesAiContracts
{
    public function observeContract(string $capability, string $surface): void
    {
        // Nothing observes contract usage in this application.
    }
}
