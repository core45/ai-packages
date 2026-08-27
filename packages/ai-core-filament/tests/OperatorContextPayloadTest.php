<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class OperatorContextPayloadTest extends TestCase
{
    /**
     * The context keys must reach the stored notification payload.
     *
     * This is the path that replaced a post-send patch of the recipient's newest
     * notification row — racy before, and broken behind the seam once an
     * implementation writes no row at all. If the keys stopped arriving, nothing
     * would fail loudly: notifications would simply render in the queue worker's
     * locale instead of the reader's, which is the kind of regression that gets
     * noticed months later by a user in the wrong language.
     */
    #[Test]
    public function context_keys_reach_the_stored_payload(): void
    {
        Notification::fake();

        $recipient = new OperatorContextRecipient;

        $this->app->make(NotifiesOperator::class)->notifyOperator(
            $recipient,
            'Pipeline finished',
            'Post ready for review.',
            NotificationSeverity::Info,
            [
                'title_key' => 'ai.pipeline.finished',
                'body_key' => 'ai.pipeline.ready',
                'body_params' => ['title' => 'A post'],
            ],
        );

        Notification::assertSentTo(
            $recipient,
            DatabaseNotification::class,
            function (DatabaseNotification $notification): bool {
                $this->assertSame('ai.pipeline.finished', $notification->data['title_key']);
                $this->assertSame('ai.pipeline.ready', $notification->data['body_key']);
                $this->assertSame(['title' => 'A post'], $notification->data['body_params']);

                // Filament's own keys must survive the merge intact. The host
                // overwrites `title` and `body` at read time from the keys above,
                // so both being present is correct — but `format` and `duration`
                // are what make the panel able to render the row at all, and a
                // caller must never be able to displace them through `$context`.
                $this->assertSame('filament', $notification->data['format']);
                $this->assertSame('persistent', $notification->data['duration']);
                $this->assertSame('info', $notification->data['status']);
                $this->assertSame('Pipeline finished', $notification->data['title']);

                return true;
            },
        );
    }

    /**
     * Without context the implementation takes Filament's own `sendToDatabase()`
     * path, which must still produce a renderable payload.
     */
    #[Test]
    public function a_notification_without_context_still_sends(): void
    {
        Notification::fake();

        $recipient = new OperatorContextRecipient;

        $this->app->make(NotifiesOperator::class)->notifyOperator(
            $recipient,
            'Photos generated',
            '3 photos attached.',
            NotificationSeverity::Success,
        );

        Notification::assertSentTo(
            $recipient,
            DatabaseNotification::class,
            function (DatabaseNotification $notification): bool {
                $this->assertSame('Photos generated', $notification->data['title']);
                $this->assertSame('success', $notification->data['status']);
                $this->assertArrayNotHasKey('title_key', $notification->data);

                return true;
            },
        );
    }

    /**
     * A caller must not be able to break the panel's rendering by passing a key
     * Filament owns.
     */
    #[Test]
    public function context_cannot_displace_filaments_own_keys(): void
    {
        Notification::fake();

        $recipient = new OperatorContextRecipient;

        $this->app->make(NotifiesOperator::class)->notifyOperator(
            $recipient,
            'Real title',
            'Real body.',
            NotificationSeverity::Danger,
            ['format' => 'not-filament', 'status' => 'success', 'title' => 'Hijacked'],
        );

        Notification::assertSentTo(
            $recipient,
            DatabaseNotification::class,
            function (DatabaseNotification $notification): bool {
                $this->assertSame('filament', $notification->data['format']);
                $this->assertSame('danger', $notification->data['status']);
                $this->assertSame('Real title', $notification->data['title']);

                return true;
            },
        );
    }
}

/**
 * Minimal notifiable. `Notification::fake()` records against the instance, so
 * this never touches the database.
 */
class OperatorContextRecipient extends Model
{
    use Notifiable;

    protected $table = 'users';

    public function getKey(): int
    {
        return 42;
    }
}
