<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

class PackageWiringTest extends TestCase
{
    /**
     * ai-core is the foundation every other package depends on. It imports no
     * host classes at all — it reaches the consuming app's models through
     * `ModelRegistry` and the capability contracts in `src/Contracts/`, which
     * is what makes it installable in any host. Every other package in this
     * monorepo already carries this exact guard (see ai-posts's
     * `PackageWiringTest::host_coupling_does_not_grow()`, ai-chat's
     * `PackageWiringTest::the_package_boots_with_none_of_its_own_code_importing_the_host()`,
     * and the Filament packages' `HostCouplingTest`); ai-core was the one gap.
     *
     * An exact assertion, not a ceiling. A ceiling satisfied at zero keeps
     * passing when the imports come back; an exact match does not.
     */
    private const MAX_HOST_IMPORTS = 0;

    /**
     * @return array<string, class-string<Model>>
     */
    private function fakeModels(): array
    {
        return [
            'widget' => PackageWiringFakeModelA::class,
            'gadget' => PackageWiringFakeModelB::class,
        ];
    }

    #[Test]
    public function the_package_boots_standalone_and_resolves_its_bindings(): void
    {
        $this->assertInstanceOf(RemoteAiClient::class, $this->app->make(AiClient::class));
        $this->assertInstanceOf(AiClientFactory::class, $this->app->make(AiClientFactory::class));

        $registry = $this->app->make(ModelRegistry::class);
        $this->assertSame([], $registry->getModels(), 'default registry must be empty until the consumer supplies its map');

        // Scoped bindings re-resolve per lifecycle: same instance within one scope.
        $this->assertSame($this->app->make(ModelRegistry::class), $this->app->make(ModelRegistry::class));
    }

    #[Test]
    public function consumer_supplied_model_registry_resolves_neutral_slugs(): void
    {
        $registry = new ModelRegistry(
            models: ['widget' => PackageWiringFakeModelA::class, 'gadget' => PackageWiringFakeModelB::class],
            urlSlugs: ['widgets' => PackageWiringFakeModelA::class],
            seoContentTypes: [PackageWiringFakeModelA::class => 'widget'],
        );

        $this->assertSame(PackageWiringFakeModelA::class, $registry->getModelClass('widget'));
        $this->assertSame('widget', $registry->getModelKey(PackageWiringFakeModelA::class));
        $this->assertNull($registry->getModelKey(static::class), 'unregistered classes resolve to null');
        $this->assertSame('widget', $registry->getSeoContentType(PackageWiringFakeModelA::class));
        $this->assertNull($registry->getSeoContentType(PackageWiringFakeModelB::class));
        $this->assertSame(PackageWiringFakeModelA::class, $registry->getModelClassFromSlug('widgets'));
    }

    #[Test]
    public function host_coupling_does_not_grow(): void
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/^use\s+(App\\\\[^\s;]+);/m', (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }

        $distinct = count(array_unique($found));

        $this->assertLessThanOrEqual(
            self::MAX_HOST_IMPORTS,
            $distinct,
            "ai-core imports {$distinct} host class(es); it must import none. "
            .'Route the dependency through ModelRegistry or a contract in src/Contracts/ '
            .'instead. Do not raise this ceiling: a package that imports App\\ classes '
            .'cannot be installed in any other application, which is the whole point of '
            .'extracting it.'
        );
    }
}

class PackageWiringFakeModelA extends Model {}

class PackageWiringFakeModelB extends Model {}
