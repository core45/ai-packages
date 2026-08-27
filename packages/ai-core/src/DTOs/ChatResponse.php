<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Response DTO for `AiClient::chat()`.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 *
 * Phase 31: `injection` (C31-2) carries phasea's `{field, locale, content,
 * main_tab|null}` payload when the inject_to_form tool fired (null otherwise);
 * `actionProposals` (C31-4) carries the list of `{action_id, action_type,
 * model_type, model_id, changes, summary}` proposals awaiting admin
 * confirmation (empty list otherwise). Both keys are always present on the
 * phasea sync envelope since Phase 31 Half A.
 */
final readonly class ChatResponse
{
    /**
     * @param  array<string, mixed>|null  $usage
     * @param  array<string, mixed>|null  $injection
     * @param  list<array<string, mixed>>  $actionProposals
     */
    public function __construct(
        public string $content,
        public string $conversationId,
        public ?array $usage,
        public ?array $injection = null,
        public array $actionProposals = [],
    ) {}

    /**
     * Parse phasea's chat envelope `{"data": {"text": "...", "conversation_id": "...",
     * "injection": {...}|null, "action_proposals": [...]}}` (docs/ai-contract.md §1
     * + Phase 31 C31-2/C31-4). No usage data exists in this envelope; `usage`
     * stays null unless phasea adds it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $envelope = $data['data'] ?? null;
        $envelope = is_array($envelope) ? $envelope : [];

        $content = $envelope['text'] ?? '';
        $conversationId = $envelope['conversation_id'] ?? '';
        $usage = $envelope['usage'] ?? null;

        return new self(
            content: is_string($content) ? $content : '',
            conversationId: is_string($conversationId) ? $conversationId : '',
            usage: self::normalizeNullableMap($usage),
            injection: self::normalizeNullableMap($envelope['injection'] ?? null),
            actionProposals: self::normalizeMapList($envelope['action_proposals'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'conversation_id' => $this->conversationId,
            'usage' => $this->usage,
            'injection' => $this->injection,
            'action_proposals' => $this->actionProposals,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeMapList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            $map = self::normalizeNullableMap($item);

            if ($map !== null) {
                $normalized[] = $map;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeNullableMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }
}
