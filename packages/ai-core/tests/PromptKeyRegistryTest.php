<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * The prompt-key catalog after D14's inversion.
 *
 * The Testbench harness boots ONLY `AiCoreServiceProvider`, so what this file
 * sees is exactly what an application that installed `ai-core` alone sees — the
 * seven keys this package owns and nothing else. That is the assertion that
 * matters: before the inversion the same harness would have offered nineteen,
 * twelve of them backed by no installed code.
 */
class PromptKeyRegistryTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const OWN_KEYS = [
        'admin_system',
        'query_instructions',
        'action_instructions',
        'customer_system',
        'anonymous_system',
        'content_generation',
        'seo_generation',
    ];

    #[Test]
    public function ai_core_registers_exactly_the_keys_it_owns(): void
    {
        $this->assertSame(self::OWN_KEYS, array_keys(app(PromptKeyRegistry::class)->all()));
    }

    /**
     * The public entry point still works — the callers in `ai-core-filament`
     * were not changed, only what answers them.
     */
    #[Test]
    public function the_model_reads_the_registry(): void
    {
        $this->assertSame(
            app(PromptKeyRegistry::class)->all(),
            AIPromptOverride::availablePromptKeys(),
        );

        $this->assertSame('Admin System Prompt', AIPromptOverride::availablePromptKeys()['admin_system']);
    }

    /**
     * No domain key reaches an ai-core-only install. This is the leak itself,
     * asserted from the outside: `product_rewrite_system` and its siblings are
     * absent until the package that owns them is installed.
     */
    #[Test]
    public function no_domain_key_is_offered_without_its_package(): void
    {
        $offered = array_keys(AIPromptOverride::availablePromptKeys());

        foreach (['product_rewrite_system', 'webinar_part_element_seo_system', 'post_seo_system'] as $domainKey) {
            $this->assertNotContains($domainKey, $offered);
        }
    }

    /**
     * Colours are carried by the registration, not by a `match` in ai-core.
     * The three groups below are the ones the old match arm defined.
     */
    #[Test]
    public function the_badge_colour_travels_with_the_key(): void
    {
        $this->assertSame('info', AIPromptOverride::promptKeyBadgeColor('admin_system'));
        $this->assertSame('success', AIPromptOverride::promptKeyBadgeColor('customer_system'));
        $this->assertSame('warning', AIPromptOverride::promptKeyBadgeColor('content_generation'));
    }

    /**
     * An override row can outlive the package that registered its key: uninstall
     * `ai-webinars` and the `webinar_seo_system` rows are still in the table. The
     * admin list has to render them, so an unknown key resolves to `gray` rather
     * than throwing.
     */
    #[Test]
    public function an_unregistered_key_is_grey_rather_than_fatal(): void
    {
        $this->assertSame('gray', AIPromptOverride::promptKeyBadgeColor('webinar_seo_system'));
        $this->assertSame('gray', AIPromptOverride::promptKeyBadgeColor('a_key_no_package_ever_registered'));
    }

    /**
     * `ai-core`'s own keys route to no phasea template — they live in phasea
     * AGENTS, not the prompt catalog — so the form preview falls back to its
     * placeholder. Preserving that null is what keeps the preview behaving as it
     * did before the map moved out of `ai-core-filament`.
     */
    #[Test]
    public function a_key_with_no_template_reports_null(): void
    {
        $this->assertNull(app(PromptKeyRegistry::class)->templateFor('admin_system'));
        $this->assertNull(app(PromptKeyRegistry::class)->templateFor('content_generation'));
    }

    #[Test]
    public function a_package_can_register_its_own_key(): void
    {
        $registry = app(PromptKeyRegistry::class);
        $registry->register('fake_domain_system', 'Fake Domain Prompt', 'danger', 'rewrite.field_rewrite');

        $this->assertTrue($registry->has('fake_domain_system'));
        $this->assertSame('Fake Domain Prompt', $registry->labelFor('fake_domain_system'));
        $this->assertSame('danger', $registry->colorFor('fake_domain_system'));
        $this->assertSame('rewrite.field_rewrite', $registry->templateFor('fake_domain_system'));
        $this->assertContains('fake_domain_system', array_keys($registry->all()));
    }

    /**
     * Last writer wins, which is what lets a consuming application relabel a
     * packaged key from its own provider instead of patching the package.
     */
    #[Test]
    public function re_registering_a_key_overwrites_it(): void
    {
        $registry = app(PromptKeyRegistry::class);
        $registry->register('admin_system', 'Relabelled By The Host');

        $this->assertSame('Relabelled By The Host', $registry->labelFor('admin_system'));
        $this->assertCount(count(self::OWN_KEYS), $registry->all());
    }

    #[Test]
    public function an_unknown_key_has_no_label(): void
    {
        $this->assertNull(app(PromptKeyRegistry::class)->labelFor('nothing_registered_this'));
        $this->assertFalse(app(PromptKeyRegistry::class)->has('nothing_registered_this'));
    }
}
