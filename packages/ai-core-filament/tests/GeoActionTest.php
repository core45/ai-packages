<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Core45\AiCore\Filament\Actions\AiCoreActions;
use Core45\AiCore\Filament\Actions\ScoreGeoAction;
use Core45\AiCore\Models\Concerns\HasGeoProfile;
use Core45\AiCore\Models\GeoProfile;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * D7: "Score GEO" ships from this package, parameterised by a ModelRegistry
 * key, instead of being copied inline into each host resource's table.
 */
class GeoActionTest extends TestCase
{
    // `AiCoreServiceProvider` loads the geo_profiles migration, so Testbench can
    // build the table the action writes through.
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', ['post' => GeoActionFakePost::class]);
        $app['config']->set('laravellocalization.supportedLocales', [
            'en' => ['name' => 'English'],
            'es' => ['name' => 'Spanish'],
        ]);
    }

    /**
     * The action reaches a host through the same single opt-in spread as the
     * rest — a host that already writes `...AiCoreActions::forTableToolbar()`
     * gets it with no further wiring.
     */
    #[Test]
    public function the_toolbar_factory_ships_the_geo_action(): void
    {
        $names = array_map(
            fn (BulkAction $action): string => $action->getName(),
            AiCoreActions::forTableToolbar('post'),
        );

        $this->assertContains('scoreGeo', $names);
    }

    /**
     * The name matches the inline action it replaces, so a host removing its
     * own copy does not change the name the action is addressed by in tests or
     * in table state — and it is still overridable for a table that needs two.
     */
    #[Test]
    public function the_default_name_matches_the_inline_action_it_replaces(): void
    {
        $this->assertSame('scoreGeo', ScoreGeoAction::makeBulkAction('post')->getName());
        $this->assertSame('rescore', ScoreGeoAction::makeBulkAction('post', 'rescore')->getName());
    }

    /**
     * A table wired with a key the host never registered names that key, rather
     * than scoring silently here and failing in a sibling action that does use
     * the resolved class.
     */
    #[Test]
    public function an_unregistered_model_key_names_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sprocket');

        $this->runAction(ScoreGeoAction::makeBulkAction('sprocket'), new GeoActionFakePost);
    }

    /**
     * The action is attached by the HOST, to a table this package has never
     * seen. A model that never took `HasGeoProfile` is therefore a wiring
     * mistake that is entirely possible, and it names the class and the trait
     * instead of fataling on an undefined method mid-sweep — with some records
     * already scored and some not.
     */
    #[Test]
    public function a_record_without_the_trait_names_itself_and_the_trait(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(HasGeoProfile::class);

        $this->runAction(ScoreGeoAction::makeBulkAction('post'), new GeoActionUnscorable);
    }

    /**
     * Every selected record is scored in every configured locale — the sweep is
     * the whole point of the bulk action, and a single-locale implementation
     * would leave the other locales stale with no error.
     */
    #[Test]
    public function every_record_is_scored_in_every_configured_locale(): void
    {
        $first = new GeoActionFakePost;
        $first->id = 1;

        $second = new GeoActionFakePost;
        $second->id = 2;

        $this->runAction(ScoreGeoAction::makeBulkAction('post'), $first, $second);

        $this->assertSame(4, GeoProfile::query()->count(), '2 records x 2 locales');

        foreach ([1, 2] as $id) {
            foreach (['en', 'es'] as $locale) {
                $this->assertDatabaseHas('geo_profiles', [
                    'model_id' => $id,
                    'locale' => $locale,
                    'score' => 100,
                    'grade' => 'A',
                ]);
            }
        }
    }

    private function runAction(BulkAction $action, Model ...$records): void
    {
        $closure = $action->getActionFunction();

        $this->assertNotNull($closure, 'The bulk action has no action closure.');

        $closure(new EloquentCollection($records));
    }
}

class GeoActionFakePost extends Model
{
    use HasGeoProfile;

    /**
     * One always-passing signal, so the score is 100 in every locale and the
     * assertions are about the sweep rather than about the scoring.
     *
     * @return array<string, mixed>
     */
    public function geoProfileData(string $locale): array
    {
        return ['applicable_signals' => ['has_json_ld'], 'has_json_ld' => true];
    }
}

class GeoActionUnscorable extends Model {}
