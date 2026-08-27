<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Enums\AiSurface;

/**
 * Resolves the correct `AiClient` implementation for a given `AiSurface`.
 *
 * Every surface resolves `RemoteAiClient`. The former detection/humanization
 * local carve-out was retired when both became async phasea poll surfaces.
 *
 * Resolution goes through `app()->make()` so the `scoped()` lifecycle of
 * `AiClient` (registered in `AiServiceProvider`) is respected — never cache
 * a resolved instance as a property on this factory.
 */
class AiClientFactory
{
    public function for(AiSurface $surface): AiClient
    {
        return app()->make(RemoteAiClient::class);
    }
}
