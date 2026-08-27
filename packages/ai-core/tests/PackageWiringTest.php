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
}

class PackageWiringFakeModelA extends Model {}

class PackageWiringFakeModelB extends Model {}
