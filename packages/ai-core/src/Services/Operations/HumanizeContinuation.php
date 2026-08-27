<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use RuntimeException;

/**
 * Composite humanize-leg context carried by PollAiDetectionJob.
 *
 * The operation ID belongs to the composite and is shared by both legs. Only
 * queue-serializable scalars are carried so the target model is re-fetched at
 * fire time.
 */
final readonly class HumanizeContinuation
{
    /**
     * @param  class-string  $modelType
     * @param  array{readability?: string, purpose?: string, strength?: string}  $options
     */
    public function __construct(
        public string $modelType,
        public int $modelId,
        public string $locale,
        public string $field,
        public array $options,
        public string $operationId,
    ) {}

    public function encode(): string
    {
        return base64_encode(serialize($this));
    }

    public static function decode(?string $payload): ?self
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new RuntimeException('The humanization continuation payload is invalid.');
        }

        $continuation = unserialize($decoded, ['allowed_classes' => true]);

        if (!$continuation instanceof self) {
            throw new RuntimeException('The humanization continuation has an unexpected type.');
        }

        return $continuation;
    }
}
