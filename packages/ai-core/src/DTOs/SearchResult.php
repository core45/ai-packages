<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

final readonly class SearchResult
{
    /**
     * @param  array<string>  $images
     */
    public function __construct(
        public string $url,
        public string $domain,
        public string $title,
        public string $snippet,
        public ?string $language = null,
        public ?string $publishedAt = null,
        public ?float $reliabilityScore = null,
        public ?string $ogImage = null,
        public array $images = [],
    ) {}
}
