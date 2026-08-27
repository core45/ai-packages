<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiCore\Models\Concerns\HasAiDetection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * `AiDetectionObserver` is attached by the provider, and until this test
 * existed it was not attached at all.
 *
 * `HasAiContent`'s docblock asserted that `AiCoreServiceProvider::boot()`
 * registered the observer for every model in the registry. Nothing in that
 * provider ever called `observe()`, so the packaged observer was dead code in
 * every application that installed this package. The reference host did not
 * notice, because its own models carry
 * `#[ObservedBy(App\Observers\AiDetectionObserver::class)]` and were running a
 * HOST copy of the class — the packaged one was never reached.
 *
 * A test that only asserted the observer's own behaviour would have passed
 * throughout: the class worked fine, nothing called it. So these assert
 * attachment, which is the part that was actually missing.
 */
class AiDetectionObserverRegistrationTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', [
            'detectable' => ObserverFakeDetectable::class,
            'plain' => ObserverFakePlain::class,
            'missing' => 'Core45\AiCore\Tests\NoSuchModelClass',
            'not_a_model' => \stdClass::class,
        ]);
    }

    #[Test]
    public function the_observer_attaches_to_registered_detectable_models(): void
    {
        config()->set('ai.assistant.ai_detection.enabled', true);

        $this->bootProvider();

        $this->assertTrue(
            Event::hasListeners('eloquent.saved: '.ObserverFakeDetectable::class),
            'The packaged AiDetectionObserver was never attached — the exact defect this test exists for.',
        );
    }

    /**
     * The flag is what keeps the registration free. It defaults to off, and the
     * loop resolves `class_exists()` on every entry in `ai.models`, which
     * autoloads and boots each one. An app that has not opted in should pay
     * nothing at all.
     */
    #[Test]
    public function nothing_is_attached_while_the_feature_is_off(): void
    {
        config()->set('ai.assistant.ai_detection.enabled', false);

        $this->bootProvider();

        $this->assertFalse(
            Event::hasListeners('eloquent.saved: '.ObserverFakeDetectable::class),
        );
    }

    /**
     * A model without `HasAiDetection` gets no listener. The observer would
     * return early anyway, so this is about not filling the dispatcher with
     * listeners that can only no-op.
     */
    #[Test]
    public function a_model_without_the_trait_is_skipped(): void
    {
        config()->set('ai.assistant.ai_detection.enabled', true);

        $this->bootProvider();

        $this->assertFalse(
            Event::hasListeners('eloquent.saved: '.ObserverFakePlain::class),
        );
    }

    /**
     * A registry naming a class that does not exist, or one that is not an
     * Eloquent model, must not take the application down at boot. Both are in
     * the fixture registry above; reaching this assertion at all is the proof,
     * since a missing guard throws while the provider is booting.
     */
    #[Test]
    public function a_bad_registry_entry_does_not_break_boot(): void
    {
        config()->set('ai.assistant.ai_detection.enabled', true);

        $this->bootProvider();

        $this->assertTrue(
            Event::hasListeners('eloquent.saved: '.ObserverFakeDetectable::class),
            'Boot survived, and the good entry was still processed.',
        );
    }

    /**
     * Re-run the provider's boot against the CURRENT config.
     *
     * `refreshApplication()` cannot be used here: it rebuilds the container
     * from `getEnvironmentSetUp()`, throwing away the flag each test just set.
     * Booting the provider directly is also the more honest unit — the defect
     * was that this boot did not attach anything.
     */
    private function bootProvider(): void
    {
        (new AiCoreServiceProvider($this->app))->boot();
    }
}

class ObserverFakeDetectable extends Model
{
    use HasAiDetection;

    /**
     * Required by the trait. Never called here — these tests assert that the
     * listener is ATTACHED, not that it runs.
     *
     * @return array<int, string>
     */
    public function getAiDetectableFields(): array
    {
        return ['main_text'];
    }
}

class ObserverFakePlain extends Model {}
