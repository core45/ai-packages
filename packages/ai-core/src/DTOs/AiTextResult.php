<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Result DTO for `AiClient::completeText()` — a generic, surface-agnostic
 * single-turn text completion (system + user prompt -> text + token usage).
 *
 * This is the contract seam used by the BlogPipeline/BlogTopics orchestrators.
 * `RemoteAiClient` maps the call onto the surface's phasea route.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 */
final readonly class AiTextResult
{
    public function __construct(
        public string $text,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = $data['text'] ?? '';
        $promptTokens = $data['prompt_tokens'] ?? null;
        $completionTokens = $data['completion_tokens'] ?? null;

        return new self(
            text: is_string($text) ? $text : '',
            promptTokens: is_int($promptTokens) ? $promptTokens : 0,
            completionTokens: is_int($completionTokens) ? $completionTokens : 0,
        );
    }
}
