<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * An in-text image, plus where in the article it belongs.
 *
 * Carries a phasea-owned template key and its parameters rather than a composed
 * prompt string, for the reason given on {@see ImagePromptItem} (plan 48-19).
 */
final readonly class InTextPromptItem
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $template,
        public array $parameters,
        public string $alt,
        public ?int $sectionIndex = null,
        public ?string $sectionHeading = null,
    ) {}
}
