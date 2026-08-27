<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Enums\GeoGrade;
use Core45\AiCore\Models\Concerns\HasGeoProfile;
use Core45\AiCore\Models\GeoProfile;
use Core45\AiCore\Services\Geo\GeoScorerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * D7: the GEO write side — scorer, grade enum and the trait's `scoreGeo()` —
 * ported into `ai-core` from a host-namespaced service.
 *
 * The fakes below are plain `Model` subclasses with no table of their own, and
 * that is not a shortcut: `GeoScorerService` never queries the model it scores.
 * It reads one array the model hands it and writes `geo_profiles` keyed on
 * `getMorphClass()` + `getKey()`. A fake that needed a table would be proving
 * something the service does not do.
 */
class GeoScoringTest extends TestCase
{
    // `AiCoreServiceProvider` loads the package migrations, so Testbench can
    // build `geo_profiles`; without this the write assertions would only prove
    // that the insert throws.
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Pin the brand so `entity_consistency` is deterministic. The shipped
        // default is the app name, which Testbench sets to its own.
        $app['config']->set('geo.site_name', 'Acme Widgets');
    }

    /**
     * Weights, the division, the rounding and the audit payload, in one case
     * chosen so that every one of them has to be right for it to pass.
     *
     * Three applicable signals worth 20 + 15 + 10 = 45, of which 20 + 10 = 30
     * pass. 30/45 is 66.66…, so a truncating implementation scores 66 and a
     * rounding one scores 67 — and 67 is a C while 66 is also a C, so the grade
     * alone would not have caught it. Assert the score too.
     */
    #[Test]
    public function the_score_is_the_passed_weight_over_the_applicable_weight(): void
    {
        $model = $this->fakeModel(1, [
            'applicable_signals' => ['has_json_ld', 'has_faq_schema', 'has_canonical'],
            'has_json_ld' => true,
            'faqs_count' => 0,
            'url' => 'https://example.test/widget',
        ]);

        $profile = app(GeoScorerService::class)->score($model, 'en');

        $this->assertSame(67, $profile->score);
        $this->assertSame(GeoGrade::C, $profile->grade);

        $this->assertSame([
            ['signal' => 'has_json_ld', 'weight' => 20, 'passed' => true, 'tip' => null],
            ['signal' => 'has_faq_schema', 'weight' => 15, 'passed' => false, 'tip' => 'Add at least one FAQ to enable FAQ schema'],
            ['signal' => 'has_canonical', 'weight' => 10, 'passed' => true, 'tip' => null],
        ], $profile->audit, 'The audit payload shape is consumed by the GEO panel; it is part of the contract.');
    }

    /**
     * A model that declares no applicable signals divides by zero unless the
     * guard holds. The source application returned 0 rather than throwing, and
     * 0 grades F.
     */
    #[Test]
    public function a_model_with_no_applicable_signals_scores_zero_not_a_division_error(): void
    {
        $profile = app(GeoScorerService::class)->score(
            $this->fakeModel(2, ['applicable_signals' => []]),
            'en',
        );

        $this->assertSame(0, $profile->score);
        $this->assertSame(GeoGrade::F, $profile->grade);
        $this->assertSame([], $profile->audit);
    }

    /**
     * `scoreGeo()` — the write helper this trait spent a release documenting as
     * absent — persists a row the read helpers can find again.
     */
    #[Test]
    public function score_geo_writes_a_profile_the_read_helpers_can_find(): void
    {
        $model = $this->fakeModel(3, [
            'applicable_signals' => ['has_json_ld'],
            'has_json_ld' => true,
        ]);

        $profile = $model->scoreGeo('en');

        $this->assertInstanceOf(GeoProfile::class, $profile);
        $this->assertDatabaseHas('geo_profiles', [
            'model_type' => $model->getMorphClass(),
            'model_id' => 3,
            'locale' => 'en',
            'score' => 100,
            'grade' => 'A',
        ]);

        $this->assertSame(100, $model->getGeoScore('en'));
        $this->assertSame(GeoGrade::A, $model->getGeoGrade('en'));
    }

    /**
     * Re-scoring the same model and locale updates the row rather than adding
     * one — the unique index on (model_type, model_id, locale) would otherwise
     * turn the second run into a constraint violation.
     */
    #[Test]
    public function re_scoring_upserts_rather_than_duplicating(): void
    {
        $model = $this->fakeModel(4, [
            'applicable_signals' => ['has_json_ld'],
            'has_json_ld' => false,
        ]);

        $first = $model->scoreGeo('en');
        $this->assertSame(0, $first->score);

        $model->geoData['has_json_ld'] = true;
        $second = $model->scoreGeo('en');

        $this->assertSame(100, $second->score);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, GeoProfile::query()->where('model_id', 4)->count());
    }

    /**
     * Locales are separate rows, not a last-writer-wins single row. The bulk
     * action sweeps every configured locale for each record, so this is the
     * behaviour it depends on.
     */
    #[Test]
    public function each_locale_gets_its_own_profile(): void
    {
        $model = $this->fakeModel(5, [
            'applicable_signals' => ['has_json_ld'],
            'has_json_ld' => true,
        ]);

        $model->scoreGeo('en');
        $model->scoreGeo('es');

        $this->assertSame(2, GeoProfile::query()->where('model_id', 5)->count());
    }

    /**
     * The host coupling this port removed.
     *
     * The source service's signature was a PHPDoc union of five host model
     * classes; anything else was undefined. Scoring is data-driven, so the
     * replacement is a CAPABILITY check — and this fake proves it, because it
     * shares no ancestor, no interface and no registry key with any of them.
     */
    #[Test]
    public function any_model_that_can_answer_geo_profile_data_is_scorable(): void
    {
        $this->assertSame(
            100,
            app(GeoScorerService::class)->score(
                $this->fakeModel(6, ['applicable_signals' => ['has_breadcrumb'], 'has_breadcrumb' => true]),
                'en',
            )->score,
        );
    }

    /**
     * ...and a model that cannot answer says so, naming itself and the trait,
     * rather than fataling on an undefined method deeper in.
     */
    #[Test]
    public function a_model_without_geo_profile_data_fails_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(HasGeoProfile::class);

        app(GeoScorerService::class)->score(new GeoScoringUnscorableModel, 'en');
    }

    /**
     * `entity_consistency` reads the brand from config, so a consuming app in
     * another domain is not scored against someone else's brand name.
     */
    #[Test]
    public function entity_consistency_matches_the_configured_site_name(): void
    {
        $scorer = app(GeoScorerService::class);

        $this->assertSame(100, $scorer->score($this->fakeModel(7, [
            'applicable_signals' => ['entity_consistency'],
            'name' => 'The best ACME WIDGETS money can buy',
        ]), 'en')->score, 'The match is case-insensitive and spans name + content.');

        $this->assertSame(0, $scorer->score($this->fakeModel(8, [
            'applicable_signals' => ['entity_consistency'],
            'name' => 'Some other brand entirely',
        ]), 'en')->score);
    }

    /**
     * The package ships the scoring thresholds.
     *
     * This is a real gate, not a config echo. `GeoScorerService` reads these
     * keys with NO inline fallback, exactly as the source did: unset,
     * `min_description_words` is null and `$wordCount >= null` compares against
     * 0, so every model passes `description_length` forever and no test that
     * only looks at scores would ever notice.
     */
    #[Test]
    public function the_scoring_thresholds_have_package_defaults(): void
    {
        $this->assertSame(150, config('geo.scoring.min_description_words'));
        $this->assertSame(10, config('geo.scoring.min_reviews_for_signal'));
        $this->assertSame(4.0, config('geo.scoring.min_rating_for_signal'));
    }

    /**
     * A host overriding ONE scoring key must not silently disable the others.
     *
     * `mergeConfigFrom` is a shallow `array_merge`, not a recursive one —
     * `Illuminate\Support\ServiceProvider::mergeConfigFrom` does
     * `array_merge(require $path, $config->get($key, []))`. So an application
     * whose `config/geo.php` sets `scoring` to a single key REPLACES this
     * package's whole `scoring` array and the sibling reads come back null.
     *
     * What makes that worth a test rather than a comment is that it fails
     * silently: `$rating >= null` compares against 0, so every model passes the
     * review-schema signal and the scores are quietly wrong — no exception, no
     * warning, just different numbers. Hence an inline default on every
     * `geo.*` read in the service.
     *
     * The two fixtures differ only in rating and review count: one clears the
     * shipped 4.0 / 10 bar and one is far below it. If a partial override were
     * dropping the thresholds, both would pass and the scores would be equal.
     */
    #[Test]
    public function a_partial_scoring_override_does_not_drop_the_other_thresholds(): void
    {
        config()->set('geo.scoring', ['min_description_words' => 200]);

        $this->assertNull(
            config('geo.scoring.min_reviews_for_signal'),
            'Precondition: a partial override really does drop the sibling keys.',
        );

        $scorer = app(GeoScorerService::class);

        $base = ['applicable_signals' => ['has_review_schema']];

        $wellReviewed = $scorer->score(
            $this->fakeModel(901, $base + ['rating' => 4.5, 'review_count' => 25]),
            'en',
        );

        $poorlyReviewed = $scorer->score(
            $this->fakeModel(902, $base + ['rating' => 2.0, 'review_count' => 1]),
            'en',
        );

        $this->assertGreaterThan(
            $poorlyReviewed->score,
            $wellReviewed->score,
            'The review-schema signal stopped discriminating, so the thresholds were lost.',
        );
    }

    /**
     * The grade boundaries, at the edges rather than in the middle of each band.
     */
    #[Test]
    public function the_grade_thresholds_are_unchanged(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D', 'F'], array_column(GeoGrade::cases(), 'value'));

        foreach ([100 => 'A', 85 => 'A', 84 => 'B', 70 => 'B', 69 => 'C', 55 => 'C', 54 => 'D', 40 => 'D', 39 => 'F', 0 => 'F'] as $score => $expected) {
            $this->assertSame($expected, GeoGrade::fromScore($score)->value, "score {$score}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeModel(int $id, array $data): GeoScoringFakeModel
    {
        $model = new GeoScoringFakeModel;
        $model->id = $id;
        $model->geoData = $data;

        return $model;
    }
}

class GeoScoringFakeModel extends Model
{
    use HasGeoProfile;

    /** @var array<string, mixed> */
    public array $geoData = [];

    /**
     * Declared as a real property, so PHP assigns it directly and Eloquent's
     * `__set()` never sees it — it is fixture wiring, not a model attribute.
     *
     * @return array<string, mixed>
     */
    public function geoProfileData(string $locale): array
    {
        return $this->geoData;
    }
}

class GeoScoringUnscorableModel extends Model {}
