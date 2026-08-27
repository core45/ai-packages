<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

/**
 * Severity of an operator-facing notification.
 *
 * These four cases are the complete set the packages ever used: every one of
 * the notification call sites replaced by `NotifiesOperator` selected exactly
 * one of `success`, `warning`, `danger` or `info`, and none set an icon, a
 * duration or an action button. The enum is deliberately not widened beyond
 * what is actually in use.
 *
 * This is a presentation hint, not a delivery instruction. An implementation
 * that has nowhere to show colour is free to ignore it.
 */
enum NotificationSeverity: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';

    /**
     * Resolve a severity from the loose string statuses the jobs pass around,
     * falling back to `Info` for anything unrecognised.
     *
     * The call sites this replaces all ended in `default => $notification->info()`,
     * so an unknown status has always meant "informational" here. Throwing
     * instead would turn a cosmetic mismatch into a failed background job.
     */
    public static function fromStatus(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Info;
    }
}
