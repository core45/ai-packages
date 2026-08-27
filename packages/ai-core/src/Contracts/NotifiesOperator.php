<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Core45\AiCore\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * Delivery seam for the short status messages the AI jobs send to the operator
 * who started the work ("Photos generated", "Pipeline failed", and so on).
 *
 * The packages used to call `Filament\Notifications\Notification::make()`
 * directly, which made a Filament panel a hard requirement of five packages
 * that otherwise have no UI. Every one of those call sites passed the same
 * three things — a title, a body and a severity — so one method carries all of
 * them.
 *
 * `ai-core` binds a logging implementation by default so the packages stay
 * loadable in an app with no panel at all. `ai-core-filament` overrides that
 * binding with one that writes a Filament database notification. An app on a
 * different panel binds its own.
 *
 * Deliberately not on this contract: icons, durations and action buttons.
 * Nothing that was converted used them, and a seam that carries options no
 * implementation is obliged to honour promises more than it can keep.
 */
interface NotifiesOperator
{
    /**
     * @param  Model  $recipient  The user to notify, already resolved by the caller.
     * @param  array<string, mixed>  $context  Extra keys persisted alongside the
     *                                         notification for hosts that re-render it
     *                                         later. The blog pipeline uses it for
     *                                         `title_key` / `body_key` / `body_params`,
     *                                         so a message queued by a worker can be
     *                                         re-translated into the reader's locale
     *                                         rather than frozen in the worker's.
     *                                         An implementation with nowhere to store
     *                                         it may ignore it; nothing reads it back
     *                                         through this interface.
     */
    public function notifyOperator(
        Model $recipient,
        string $title,
        string $body = '',
        NotificationSeverity $severity = NotificationSeverity::Info,
        array $context = [],
    ): void;
}
