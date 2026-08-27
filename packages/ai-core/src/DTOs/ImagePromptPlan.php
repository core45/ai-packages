<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

final readonly class ImagePromptPlan
{
    /**
     * @param  array<int, ImagePromptItem>  $gallery
     * @param  array<int, InTextPromptItem>  $inText
     */
    public function __construct(
        public ?ImagePromptItem $featured,
        public array $gallery,
        public array $inText,
    ) {}
}
