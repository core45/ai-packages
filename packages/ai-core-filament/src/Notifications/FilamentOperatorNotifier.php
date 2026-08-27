<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Notifications;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as NotificationDispatcher;

/**
 * Delivers operator notifications as Filament database notifications — the bell
 * menu in the panel.
 *
 * This is the implementation the packages had inlined at 13 call sites before
 * the `NotifiesOperator` seam existed. Installing this package restores exactly
 * that behaviour; not installing it leaves `ai-core`'s logging default in place.
 *
 * `NotificationSeverity`'s backing values are deliberately the same four strings
 * Filament's own `success()` / `warning()` / `danger()` / `info()` helpers set,
 * so the severity maps across with no translation table to drift.
 */
final class FilamentOperatorNotifier implements NotifiesOperator
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function notifyOperator(
        Model $recipient,
        string $title,
        string $body = '',
        NotificationSeverity $severity = NotificationSeverity::Info,
        array $context = [],
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->status($severity->value);

        if ($context === []) {
            $notification->sendToDatabase($recipient);

            return;
        }

        // `sendToDatabase()` is `$recipient->notify($notification->toDatabase())`,
        // and `toDatabase()` wraps `getDatabaseMessage()` in Filament's own
        // `DatabaseNotification`. Building that wrapper directly is the same
        // write, with the caller's context merged into the stored payload.
        //
        // Dispatched through the facade rather than `$recipient->notify()`:
        // `notify()` comes from the `Notifiable` trait, not from `Model`, and the
        // contract can only promise a `Model`. The facade takes any notifiable
        // and honours `ShouldQueue` on `DatabaseNotification` exactly as
        // `sendToDatabase()` does.
        //
        // Filament's own keys win on collision, so a caller cannot overwrite
        // `format` or `duration` and produce a row the panel fails to render.
        NotificationDispatcher::send($recipient, new DatabaseNotification(
            array_merge($context, $notification->getDatabaseMessage()),
        ));
    }
}
