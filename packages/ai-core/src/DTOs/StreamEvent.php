<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Typed SSE stream event yielded by `AiClient::streamChat()`.
 *
 * `$type` is one of the host's stable 6-event schema values (D17):
 * chunk, tool_start, tool_complete, error, done, heartbeat.
 *
 * Payloads are versioned (`v: 1`) so consumers can evolve the schema safely.
 */
final readonly class StreamEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload,
        public int $version = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? '';
        $payload = $data['payload'] ?? [];
        $version = $data['v'] ?? 1;

        return new self(
            type: is_string($type) ? $type : '',
            payload: self::normalizePayload($payload),
            version: is_int($version) ? $version : 1,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizePayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'v' => $this->version,
        ];
    }
}
