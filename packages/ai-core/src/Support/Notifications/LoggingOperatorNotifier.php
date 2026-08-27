<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Notifications;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Default `NotifiesOperator` implementation: writes the notification to the log
 * and delivers it nowhere.
 *
 * This exists so that installing any of these packages into an app with no
 * admin panel works rather than fataling on an unbound interface. It is a
 * fallback, not a feature — an app that wants operators to actually see these
 * messages binds its own implementation, and `ai-core-filament` does exactly
 * that.
 *
 * It logs rather than doing nothing silently. A background job reporting
 * "pipeline failed" into a void that leaves no trace is the kind of failure
 * that gets diagnosed twice.
 */
final class LoggingOperatorNotifier implements NotifiesOperator
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
        Log::log($this->logLevel($severity), 'AI operator notification: '.$title, [
            'recipient_type' => $recipient::class,
            'recipient_id' => $recipient->getKey(),
            'body' => $body,
            'severity' => $severity->value,
            'context' => $context,
            'delivered' => false,
        ]);
    }

    /**
     * `danger` is the only case that represents something going wrong; the
     * other three are progress reports and would be noise at error level.
     */
    private function logLevel(NotificationSeverity $severity): string
    {
        return match ($severity) {
            NotificationSeverity::Danger => 'error',
            NotificationSeverity::Warning => 'warning',
            NotificationSeverity::Success, NotificationSeverity::Info => 'info',
        };
    }
}
