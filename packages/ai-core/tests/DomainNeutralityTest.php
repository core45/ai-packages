<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Services\UsageAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class DomainNeutralityTest extends TestCase
{
    // Two of these call a service that queries `ai_conversation_messages`.
    // AiCoreServiceProvider loads the package migrations, so Testbench can build
    // the schema; without this the assertions would only prove that the query
    // throws.
    use RefreshDatabase;

    /**
     * The message-topic taxonomy is configuration, not code.
     *
     * `ai-core` is the domain-neutral foundation: it must not know that the
     * consuming app sells things. Two services used to hardcode an e-commerce
     * taxonomy — orders, products, payments — in two different shapes, so a
     * host in another domain got its chat traffic bucketed into categories that
     * did not exist for it, and had no way to change them.
     *
     * The shipped defaults keep that taxonomy so no existing install changes
     * behaviour; what matters is that they are now overridable.
     */
    #[Test]
    public function the_topic_taxonomy_comes_from_config(): void
    {
        config()->set('ai.topic_patterns', [
            'recipes' => ['/recipe/i', '/ingredient/i'],
        ]);

        $this->assertSame(
            ['recipes' => ['/recipe/i', '/ingredient/i']],
            config('ai.topic_patterns'),
            'A consuming app must be able to replace the taxonomy wholesale.'
        );

        // The service reads the key at call time rather than caching it at
        // construction, which is what makes an override take effect.
        // The list is re-indexed and sorted by count, so assert on the `topic`
        // field rather than on array keys.
        $distribution = app(UsageAnalyticsService::class)->getTopicDistribution(1);

        $this->assertSame(['recipes'], $distribution->pluck('topic')->all());
    }

    /**
     * An empty taxonomy must degrade, not crash.
     *
     * A host that wants no topic analytics at all sets the key to `[]`, and the
     * services have to survive that: `array_fill_keys([], 0)` is a valid empty
     * map, and every loop over it is a no-op.
     */
    #[Test]
    public function an_empty_taxonomy_is_survivable(): void
    {
        config()->set('ai.topic_patterns', []);

        $this->assertSame([], app(UsageAnalyticsService::class)->getTopicDistribution(1)->all());
    }

    /**
     * No source file in ai-core may name a host domain concept in a way that
     * only one kind of application could use.
     *
     * This is the executable form of the rule the taxonomy broke. It scans for
     * the specific pattern that went wrong — a literal array key naming an
     * e-commerce bucket — rather than for the words themselves, which appear
     * legitimately in comments and in the ContentArchetype domain.
     */
    #[Test]
    public function no_service_hardcodes_an_ecommerce_taxonomy(): void
    {
        $offenders = [];
        $root = __DIR__.'/../src';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (["'orders' =>", "'payments' =>", "'cart' =>", "'checkout' =>"] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1).' contains '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, "ai-core is domain-neutral; move this to config:\n".implode("\n", $offenders));
    }
}
