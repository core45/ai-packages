<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiInvalidResponseException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiRequestRejectedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Throwable;

trait ConvertsThrowableToErrorCode
{
    protected function errorCodeForThrowable(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof AiBudgetExceededException => 'budget_exceeded',
            $throwable instanceof AiRateLimitedException => 'rate_limited',
            $throwable instanceof AiRequestRejectedException && $throwable->status === 409 => 'operation_id_conflict',
            $throwable instanceof AiRequestRejectedException && $throwable->status === 404 => 'operation_not_found',
            $throwable instanceof AiRequestRejectedException => 'request_rejected',
            $throwable instanceof AiInvalidResponseException => 'invalid_response',
            $throwable instanceof AiUnavailableException => 'phasea_unavailable',
            default => 'unexpected',
        };
    }
}
