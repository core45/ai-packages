<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

final readonly class ImagePromptRequest
{
    /**
     * @param  array<int, string>  $headings  Ordered H2 section headings (articles only)
     */
    public function __construct(
        public string $title,
        public string $excerpt,
        public string $bodyPlain,
        public array $headings,
        public string $locale,
        public bool $featured = false,
        public int $gallery = 0,
        public int $inText = 0,
        public ?string $entityContext = null,
        public ?object $profile = null, // tenant ImageDirectorProfile (consumer-owned model; duck-typed)
        public ?object $style = null, // tenant ImageStyle (consumer-owned model; duck-typed)
    ) {}
}
