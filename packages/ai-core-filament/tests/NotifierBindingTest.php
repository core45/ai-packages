<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Filament\Notifications\FilamentOperatorNotifier;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use PHPUnit\Framework\Attributes\Test;

class NotifierBindingTest extends TestCase
{
    /**
     * The whole point of installing this package: operator notifications stop
     * going to the log and start reaching the panel.
     *
     * `ai-core` binds `LoggingOperatorNotifier` with `bindIf` and registers
     * first, so this package must bind unconditionally to displace it. Getting
     * that backwards would not fail loudly — notifications would simply keep
     * going to the log, and the panel's bell would stay empty with nothing to
     * explain why.
     */
    #[Test]
    public function installing_this_package_upgrades_delivery_to_filament(): void
    {
        $notifier = $this->app->make(NotifiesOperator::class);

        $this->assertInstanceOf(FilamentOperatorNotifier::class, $notifier);
        $this->assertNotInstanceOf(LoggingOperatorNotifier::class, $notifier);
    }
}
