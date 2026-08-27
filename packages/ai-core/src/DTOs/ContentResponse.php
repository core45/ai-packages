<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Response DTO for `AiClient::generateContent()`/`generateSEO()`.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 */
final readonly class ContentResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $content,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $content = $data['content'] ?? '';
        $metadata = $data['metadata'] ?? [];

        return new self(
            content: is_string($content) ? $content : '',
            metadata: self::normalizeMap($metadata),
        );
    }

    /**
     * Build from phasea's content-generation envelope
     * `{"data": {"generated_content": "...", "context_used": "...", "meta": {...}}}`
     * (docs/ai-contract.md §2). Named constructor (additive, 13-04) so the
     * legacy fromArray() shape that generateSEO() still consumes stays
     * untouched until Plan 13-05 moves SEO onto its own DTO. No usage data
     * exists in this envelope — callers' isset() guards degrade to zeros.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromGeneratedContentEnvelope(array $data): self
    {
        $envelope = $data['data'] ?? null;
        $envelope = is_array($envelope) ? $envelope : [];

        $content = $envelope['generated_content'] ?? '';

        return new self(
            content: is_string($content) ? $content : '',
            metadata: self::normalizeMap($envelope),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }
}
