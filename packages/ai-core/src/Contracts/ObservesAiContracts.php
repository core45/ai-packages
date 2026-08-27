<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

/**
 * Records what the host observed about a phasea prompt contract on a given
 * surface — used only for exit-window observability, never to branch a render.
 */
interface ObservesAiContracts
{
    /**
     * `blog_pipeline.module` accepts typed `placeholder_data` and formats every
     * model-visible value itself. Mirrors phasea's
     * `App\AI\Prompts\PromptCapabilities::TYPED_MODULE_PLACEHOLDERS`.
     */
    public const string TYPED_MODULE_PLACEHOLDERS = 'blog_pipeline.module.typed_placeholders';

    /**
     * Record what this host observed about a capability on this surface.
     *
     * MUST NOT THROW INTO A RENDER PATH: implementations must guard every
     * failure mode (probe, cache, log) themselves.
     */
    public function observeContract(string $capability, string $surface): void;
}
