<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;

class PackagedViewsTest extends TestCase
{
    /**
     * Every `ai-core::` view the actions and form components render resolves.
     *
     * This replaces a static check rather than merely working around one.
     * Larastan resolves `view-string` against the application's view path, so it
     * cannot see a namespace the package registers at runtime — the names in the
     * source are already literals, and no way of writing them would help.
     *
     * The failure it guards against is real and silent in a different way: the
     * views moved packages when the UI was split out, and a view left behind
     * throws only when a user opens the modal that renders it.
     */
    #[Test]
    public function every_packaged_view_resolves(): void
    {
        foreach ([
            'ai-core::components.ai-detection-results',
            'ai-core::components.similarity-results',
            'ai-core::forms.components.cornerstone-toggle',
            'ai-core::forms.components.geo-audit-panel',
            'ai-core::forms.components.readability-analysis-panel',
            'ai-core::forms.components.seo-analysis-panel',
            'ai-core::forms.components.serp-preview',
            'ai-core::forms.components.social-preview',
        ] as $view) {
            $this->assertTrue(
                View::exists($view),
                "{$view} does not resolve. The view namespace is registered by AiCoreFilamentServiceProvider::boot()."
            );
        }
    }

    /**
     * The translation namespace is registered by the DOMAIN package, not this
     * one, because the strings need no panel. If that split ever regressed the
     * labels would silently render as raw keys.
     */
    #[Test]
    public function the_packaged_translation_namespace_resolves(): void
    {
        $this->assertNotSame(
            'ai-core::ai_knowledge.navigation',
            __('ai-core::ai_knowledge.navigation'),
            'The ai-core translation namespace is not registered; keys render as themselves.'
        );
    }
}
