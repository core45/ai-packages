<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * One planned image: the phasea-owned prompt TEMPLATE that will compose the
 * instruction, plus the structured parameters it composes from.
 *
 * v2.6 design §2.5 (plan 48-19): this used to hold a ready-to-send `prompt`
 * string, because `ImagePromptDirector::decoratePrompt()` composed it host-side
 * after phasea returned the plan. Both the decoration frame and the per-slot
 * fallback wording now live in phasea, so there is no host-side string left to
 * carry — a fallback item has no base prompt at all, only a slot name. The
 * consumer passes these two fields straight to
 * `AiClient::generateImageFromTemplate()`.
 */
final readonly class ImagePromptItem
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $template,
        public array $parameters,
        public string $alt,
    ) {}
}
