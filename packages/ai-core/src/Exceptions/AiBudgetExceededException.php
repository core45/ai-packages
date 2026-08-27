<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when the tenant's usage budget on the remote AI service is exhausted.
 */
final class AiBudgetExceededException extends AiClientException
{
    public static function forTenant(string $operationId): self
    {
        return new self('AI service tenant budget exceeded', $operationId);
    }
}
