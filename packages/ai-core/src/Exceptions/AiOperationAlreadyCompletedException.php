<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when phasea answers 409 `{code: operation_already_completed}`: a PRIOR
 * attempt already produced and metered a result for this exact (deterministic)
 * operation_id, so phasea refuses to re-run and re-bill it.
 *
 * This is NOT a failure. It is the expected answer when a queued translation job
 * retries a whole content object after a transient provider blip: the fields that
 * ALREADY succeeded on the first attempt carry stable UUIDv5 ids, so their re-send
 * lands here. The caller must treat such a field as done — reconciling against the
 * locally-persisted translation — rather than reporting it as a failed field.
 *
 * Deliberately DISTINCT from {@see AiRequestRejectedException}: a plain 409
 * (`operation_in_flight`) means a concurrent sibling still owns the row and the
 * request genuinely conflicts, whereas this one means the work is finished. Phasea
 * owns that distinction (it renders two codes from one status); this typed exception
 * carries it across the wire so the host never has to parse the message string.
 */
class AiOperationAlreadyCompletedException extends AiClientException
{
    public static function forOperation(string $operationId): self
    {
        return new self(
            "AI service reports operation already completed under this id (status: 409, operation: {$operationId})",
            $operationId,
        );
    }
}
