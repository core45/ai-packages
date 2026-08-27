<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Tests;

use Core45\AiPosts\Filament\Resources\BlogExternalLinks\BlogExternalLinkResource;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\BlogTopicBatchResource;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Core45\AiPosts\Filament\Resources\ContentModules\ContentModuleResource;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;

class PackagedResourcesTest extends TestCase
{
    /**
     * Every blade view the package renders resolves.
     *
     * Two of these were unnamespaced host views before the move
     * (`filament.blog-topics.candidate-details`,
     * `filament.blog-pipeline.lesson-details`). They resolved only because the
     * one application using them happened to have the files in its own
     * `resources/views`, and the failure mode elsewhere is the worst kind: the
     * screen loads, and the modal throws when a user clicks into it.
     */
    #[Test]
    public function every_packaged_view_resolves(): void
    {
        foreach ([
            'ai-posts::filament.blog-pipeline.post-pipeline-page',
            'ai-posts::filament.blog-pipeline.lesson-details',
            'ai-posts::filament.blog-topics.candidate-details',
        ] as $view) {
            $this->assertTrue(
                View::exists($view),
                "{$view} does not resolve. The namespace is registered by AiPostsFilamentServiceProvider::boot()."
            );
        }
    }

    /**
     * The pipeline's `blog-pipeline.*` config is merged by the DOMAIN package.
     *
     * Fourteen call sites read these keys and the package shipped no such file,
     * so every one fell back to its inline default. Those defaults disagree:
     * `summarization_max_words` reads 300 inline against 800 in the config, so
     * an install without the file silently summarised to well under half the
     * intended length.
     *
     * `modules` is deliberately NOT asserted non-empty — an empty class map is
     * the normal state, because `ModuleResolver` falls back to the
     * `content_modules` table. Asserting otherwise was this test's own first
     * mistake.
     */
    #[Test]
    public function the_blog_pipeline_config_is_merged(): void
    {
        $this->assertIsArray(config('blog-pipeline.modules'));
        $this->assertSame(800, config('blog-pipeline.summarization_max_words'));
        $this->assertSame(6.0, config('blog-pipeline.quality_threshold'));
        $this->assertSame(30, config('blog-pipeline.stale_after_minutes'));
        $this->assertNotEmpty(config('blog-pipeline.forbidden_patterns'));
    }

    /**
     * One navigation group governs the whole package.
     *
     * The pipeline resource used to read a different config key from the four
     * trees moved out of the host, so a consumer setting either one got a
     * navigation split across two groups.
     */
    #[Test]
    public function one_config_key_moves_every_resource(): void
    {
        config()->set('ai-posts.filament.navigation_group', 'Blog');

        foreach ([
            BlogPostPipelineRunResource::class,
            BlogExternalLinkResource::class,
            BlogTopicBatchResource::class,
            BlogTopicProfileResource::class,
            ContentModuleResource::class,
        ] as $resource) {
            $this->assertSame('Blog', $resource::getNavigationGroup(), "{$resource} ignored the package navigation group.");
        }
    }

    /**
     * Unset, the package falls back to ai-core's group rather than inventing one.
     */
    #[Test]
    public function the_navigation_group_falls_back_to_ai_core(): void
    {
        config()->set('ai-posts.filament.navigation_group', null);
        config()->set('ai.filament.navigation_group', 'Artificial Intelligence');

        $this->assertSame(
            'Artificial Intelligence',
            ContentModuleResource::getNavigationGroup()
        );
    }
}
