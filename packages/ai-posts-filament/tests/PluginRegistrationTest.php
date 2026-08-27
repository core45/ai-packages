<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Tests;

use Core45\AiCore\Filament\AiCoreFilamentServiceProvider;
use Core45\AiPosts\Filament\Actions\AiPostsActions;
use Core45\AiPosts\Filament\Actions\AiRewriteContentAction;
use Core45\AiPosts\Filament\Actions\EnrichInternalLinksAction;
use Core45\AiPosts\Filament\Actions\GeneratePostPhotosAction;
use Core45\AiPosts\Filament\Actions\ManagePostPipelineAction;
use Core45\AiPosts\Filament\AiPostsFilamentServiceProvider;
use Core45\AiPosts\Filament\AiPostsPlugin;
use Core45\AiPosts\Filament\Pages\ManagePostPipeline;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\BlogExternalLinkResource;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\BlogProductSeriesResource;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\RelationManagers\CandidatesRelationManager;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\BlogTopicBatchResource;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Core45\AiPosts\Filament\Resources\ContentModules\ContentModuleResource;
use Filament\Panel;
use PHPUnit\Framework\Attributes\Test;

class PluginRegistrationTest extends TestCase
{
    /**
     * The four providers boot together without a fatal.
     *
     * This is the case that would have caught the merge's real breakage: a
     * signature that does not match its interface, or a class referencing one
     * that was never copied across, fails when the class is LOADED — not when
     * it is called. Booting the chain is what makes that happen in a test
     * rather than on the first request.
     */
    #[Test]
    public function the_package_boots_on_top_of_its_dependencies(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(AiPostsFilamentServiceProvider::class));
        $this->assertTrue($this->app->providerIsLoaded(AiCoreFilamentServiceProvider::class));
    }

    /**
     * The plugin registers exactly what this package owns.
     *
     * `BlogPostPipelineRun` is a package-owned model, so its resource is
     * shippable and appears with no wiring. Nothing here registers a resource
     * for a host-owned model, because no package can.
     */
    #[Test]
    public function the_plugin_registers_the_pipeline_resource_and_page(): void
    {
        $panel = Panel::make()->id('testing');

        AiPostsPlugin::make()->register($panel);

        $this->assertContains(BlogPostPipelineRunResource::class, $panel->getResources());
        $this->assertContains(BlogExternalLinkResource::class, $panel->getResources());
        $this->assertContains(BlogTopicBatchResource::class, $panel->getResources());
        $this->assertContains(BlogTopicProfileResource::class, $panel->getResources());
        $this->assertContains(ContentModuleResource::class, $panel->getResources());
        $this->assertContains(BlogProductSeriesResource::class, $panel->getResources());
        $this->assertContains(ManagePostPipeline::class, $panel->getPages());
    }

    #[Test]
    public function every_shipped_class_exists(): void
    {
        foreach ([
            AiPostsPlugin::class,
            AiPostsFilamentServiceProvider::class,
            BlogPostPipelineRunResource::class,
            ManagePostPipeline::class,
            AiPostsActions::class,
            AiRewriteContentAction::class,
            EnrichInternalLinksAction::class,
            GeneratePostPhotosAction::class,
            ManagePostPipelineAction::class,
            BlogProductSeriesResource::class,
        ] as $class) {
            $this->assertTrue(class_exists($class) || trait_exists($class), "{$class} is missing.");
        }
    }

    /**
     * `BlogProductSeries` was the one bucket-(a) tree that never moved.
     *
     * Its model, enum and factory went into `ai-posts` with the rest of the blog
     * domain, but the Filament tree stayed in the host — §3's matrix put "the 9
     * in-scope models AND their Filament sub-namespaces" in that bucket, and D23
     * never gave the UI half a disposition, so it fell between the two. This
     * asserts the seams the move needed, both of which already existed for the
     * sibling trees: the navigation group comes from config rather than the
     * host's enum, and the host's Post resource is optional.
     */
    #[Test]
    public function the_product_series_resource_owns_no_host_class(): void
    {
        // Nothing configured: the fallback chain still yields a usable group,
        // and the host's own NavigationGroup enum is never named.
        $this->assertSame('AI', BlogProductSeriesResource::getNavigationGroup());

        config()->set('ai-posts.filament.navigation_group', 'Blog');
        $this->assertSame('Blog', BlogProductSeriesResource::getNavigationGroup());
    }

    /**
     * The relation manager links to the host's Post resource when the host names
     * one, and omits the action entirely when it does not — never a dead link.
     */
    #[Test]
    public function the_candidates_relation_manager_treats_the_post_resource_as_optional(): void
    {
        $resolve = new \ReflectionMethod(CandidatesRelationManager::class, 'resolvePostResourceClass');
        $resolve->setAccessible(true);

        config()->set('ai-posts.filament.post_resource', null);
        $this->assertNull($resolve->invoke(null));

        config()->set('ai-posts.filament.post_resource', 'A\\Class\\That\\Does\\Not\\Exist');
        $this->assertNull($resolve->invoke(null), 'a configured-but-missing class must not be returned');

        config()->set('ai-posts.filament.post_resource', BlogProductSeriesResource::class);
        $this->assertSame(BlogProductSeriesResource::class, $resolve->invoke(null));
    }
}
