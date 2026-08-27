<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when phasea rejects a request because the requested upstream vendor
 * is not configured on the service (422 `{code: unsupported_provider}` —
 * e.g. grounded search with `provider=perplexity` while phasea holds no
 * Perplexity key).
 *
 * This is a CONFIGURATION error, not a transient failure and not a generic
 * validation rejection: selecting the provider in host config while phasea
 * cannot serve it must surface loudly (Phase 32, audit C1/C2) instead of
 * degrading to a silent empty result set. Subclasses
 * {@see AiRequestRejectedException} so every existing permanent-4xx handler
 * (job middleware, error classifiers) keeps treating it as non-retryable.
 */
final class AiProviderNotConfiguredException extends AiRequestRejectedException
{
    public static function forOperation(string $operationId): self
    {
        return new self(
            'AI service reports the requested provider is not configured (code: unsupported_provider)',
            422,
            $operationId,
        );
    }
}
