<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Support\Geo\NullCitationEnricher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `bindIf`, proven — a host binding made BEFORE this package registers survives
 * it.
 *
 * **The ordering is the entire test, and getting it wrong makes it vacuous.**
 * Binding after the providers have run proves nothing: a later `bind()`
 * overrides an earlier `bind()` exactly as well as it overrides an earlier
 * `bindIf()`. Such a test asserts a property of Laravel's container, not of this
 * package, and passes identically if someone changes `bindIf` to `bind`. An
 * earlier draft did precisely that, and an adversarial review caught it.
 *
 * `HostBindsFirst` is registered ahead of `AiCoreServiceProvider` below, so the
 * host's binding is already in the container when `bindIf` runs.
 *
 * It lives in its OWN test class for a reason worth stating: `getPackageProviders()`
 * applies to every test in a class, so registering this provider alongside the
 * fresh-install assertions poisoned them — `FreshInstallBindingsTest` began
 * seeing the host's enricher instead of the null default and failed. A fixture
 * that changes the world for its whole class cannot share a class with tests
 * that assert the unchanged world.
 *
 * Why it matters at all: adding defaults must not take a feature away from an
 * application that already had one. A fix that breaks the installs which were
 * working is not a fix.
 */
class HostBindingSurvivesPackageDefaultsTest extends TestCase
{
    #[Test]
    public function a_host_binding_registered_first_survives_the_package_defaults(): void
    {
        $this->assertInstanceOf(HostCitationEnricher::class, app(EnrichesCitations::class));
        $this->assertNotInstanceOf(NullCitationEnricher::class, app(EnrichesCitations::class));

        // The host's answer, not the null object's — proof the real
        // implementation is the one in use, not merely the one registered.
        $this->assertTrue(app(EnrichesCitations::class)->supports(new class extends Model {}));
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HostBindsFirst::class, ...parent::getPackageProviders($app)];
    }
}

/**
 * Stands in for a consuming application that has already chosen its own
 * implementation, and registers before this package does.
 */
final class HostBindsFirst extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnrichesCitations::class, HostCitationEnricher::class);
    }
}

final class HostCitationEnricher implements EnrichesCitations
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function enrich(Model $model, string $locale): void {}
}
