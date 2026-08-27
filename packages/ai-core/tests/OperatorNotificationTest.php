<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class OperatorNotificationTest extends TestCase
{
    /**
     * This is the case that matters: an unbound `NotifiesOperator` would fatal
     * in an app with no Filament panel, which is exactly the app this seam
     * exists to support. `bindIf` in AiCoreServiceProvider must resolve a
     * usable default rather than leaving the interface unbound.
     */
    #[Test]
    public function the_default_binding_resolves_without_a_panel(): void
    {
        $this->assertInstanceOf(LoggingOperatorNotifier::class, $this->app->make(NotifiesOperator::class));
    }

    #[Test]
    public function an_application_binding_wins_over_the_default(): void
    {
        $fake = new OperatorNotificationFakeNotifier;

        $this->app->bind(NotifiesOperator::class, fn () => $fake);

        $this->assertSame($fake, $this->app->make(NotifiesOperator::class));
    }

    #[Test]
    public function unknown_statuses_fall_back_to_info(): void
    {
        $this->assertSame(NotificationSeverity::Success, NotificationSeverity::fromStatus('success'));
        $this->assertSame(NotificationSeverity::Warning, NotificationSeverity::fromStatus('warning'));
        $this->assertSame(NotificationSeverity::Danger, NotificationSeverity::fromStatus('danger'));
        $this->assertSame(NotificationSeverity::Info, NotificationSeverity::fromStatus('info'));

        $this->assertSame(NotificationSeverity::Info, NotificationSeverity::fromStatus(null));
        $this->assertSame(NotificationSeverity::Info, NotificationSeverity::fromStatus(''));
        $this->assertSame(NotificationSeverity::Info, NotificationSeverity::fromStatus('nonsense'));
    }

    #[Test]
    public function the_logging_notifier_records_severity_body_and_context(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $title, array $context): bool {
                return $level === 'error'
                    && $title === 'AI operator notification: Pipeline failed'
                    && $context['body'] === 'Something broke'
                    && $context['severity'] === NotificationSeverity::Danger->value
                    && $context['context'] === ['job_id' => 42]
                    && $context['delivered'] === false;
            });

        $notifier = new LoggingOperatorNotifier;
        $recipient = new OperatorNotificationFakeRecipient;

        $notifier->notifyOperator(
            $recipient,
            'Pipeline failed',
            'Something broke',
            NotificationSeverity::Danger,
            ['job_id' => 42],
        );

        $this->assertLoggedAtLevel('warning', NotificationSeverity::Warning);
        $this->assertLoggedAtLevel('info', NotificationSeverity::Success);
        $this->assertLoggedAtLevel('info', NotificationSeverity::Info);
    }

    private function assertLoggedAtLevel(string $expectedLevel, NotificationSeverity $severity): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $title, array $context) use ($expectedLevel, $severity): bool {
                return $level === $expectedLevel
                    && $context['severity'] === $severity->value
                    && $context['delivered'] === false;
            });

        (new LoggingOperatorNotifier)->notifyOperator(
            new OperatorNotificationFakeRecipient,
            'Status update',
            'Body',
            $severity,
        );
    }
}

class OperatorNotificationFakeNotifier implements NotifiesOperator
{
    public function notifyOperator(
        Model $recipient,
        string $title,
        string $body = '',
        NotificationSeverity $severity = NotificationSeverity::Info,
        array $context = [],
    ): void {
        // No-op: this fake only needs to be a distinct instance the container can resolve.
    }
}

class OperatorNotificationFakeRecipient extends Model
{
    protected $attributes = ['id' => 7];

    public function getKey(): mixed
    {
        return 7;
    }
}
