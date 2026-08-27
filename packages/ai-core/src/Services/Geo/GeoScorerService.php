<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Geo;

use Core45\AiCore\Enums\GeoGrade;
use Core45\AiCore\Models\Concerns\HasGeoProfile;
use Core45\AiCore\Models\GeoProfile;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Scores a model for GEO (Generative Engine Optimization) and upserts its
 * {@see GeoProfile} row.
 *
 * D7: this is a faithful port of the source application's
 * `App\Services\Geo\GeoScorerService` — same eleven signals, same weights, same
 * pass conditions, same `round()` formula, same audit payload shape, same
 * `updateOrCreate()` keys.
 *
 * WHAT CHANGED, AND WHY IT IS SAFE
 *
 * The source service's only host coupling was its signature:
 *
 *     @ param Product|Post|ProductCategory|Dealer|BusinessLocation $model
 *
 * — FIVE `use App\Models\…` imports that appear in that union and nowhere else.
 * There was no `instanceof` branch, no class check and no per-type scoring path
 * to route through `ModelRegistry` — named here without a `@see` link on
 * purpose, because a linked reference makes the formatter add an import for a
 * class this file must not depend on. Scoring is entirely data-driven: it reads
 * a flat array the model itself returns from `geoProfileData($locale)`, so
 * resolving a registry key here would produce a class this method never uses.
 *
 * The sixth host import, `App\Models\GeoProfile`, was NOT PHPDoc-only — it is
 * the `updateOrCreate()` target below, and it is replaced by this package's own
 * model of the same shape. Same for the seventh, `App\Enums\GeoGrade`, replaced
 * by {@see GeoGrade}. Those two are real class substitutions, not annotations.
 *
 * So the union is replaced by a CAPABILITY check rather than a class check: any
 * Eloquent model that can answer `geoProfileData()` — which is what
 * {@see HasGeoProfile} declares abstract — is scorable, whatever its namespace
 * or registry key. That is a strict superset of the five host models, so no
 * previously-scorable model stops being scorable.
 *
 * The parameter is NOT typed as an intersection with a contract: the trait's
 * existing consumers apply `HasGeoProfile` without implementing any interface,
 * and narrowing the type would reject them at the boundary. The guard below
 * fails loud instead, naming the class and the trait, in the same spirit as
 * `ModelRegistry::getModelClassOrFail()`.
 *
 * Configuration comes from `config('geo.*')`, whose defaults this package now
 * ships (`config/geo.php`). A consuming app's own `config/geo.php` still wins
 * key-by-key — `mergeConfigFrom()` merges recursively with the app's values on
 * top — so an existing install's thresholds are untouched.
 */
class GeoScorerService
{
    /**
     * Signal weights, unchanged from the source application.
     *
     * These are RELATIVE, not absolute: `score()` divides by the total weight of
     * the signals a model declares applicable, so a model that opts into four of
     * them can still reach 100.
     *
     * @var array<string, int>
     */
    private const SIGNAL_WEIGHTS = [
        'has_json_ld' => 20,
        'has_faq_schema' => 15,
        'entity_consistency' => 15,
        'has_geo_coordinates' => 15,
        'has_review_schema' => 10,
        'description_length' => 10,
        'has_specific_numbers' => 10,
        'has_canonical' => 10,
        'has_breadcrumb' => 5,
        'content_density' => 5,
        'has_opening_hours' => 5,
    ];

    /**
     * @param  Model  $model  any Eloquent model exposing `geoProfileData(string $locale): array`,
     *                        which {@see HasGeoProfile} declares abstract
     *
     * @throws InvalidArgumentException when the model cannot supply GEO data
     */
    public function score(Model $model, string $locale): GeoProfile
    {
        if (!method_exists($model, 'geoProfileData')) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be GEO-scored: it must use %s (or otherwise expose geoProfileData(string $locale): array).',
                $model::class,
                HasGeoProfile::class,
            ));
        }

        /** @var array<string, mixed> $data */
        $data = $model->geoProfileData($locale);

        /** @var list<string> $applicableSignals */
        $applicableSignals = $data['applicable_signals'] ?? [];

        /** @var list<array{signal: string, weight: int, passed: bool, tip: string|null}> $audit */
        $audit = [];
        $passedWeight = 0;
        $totalWeight = 0;

        foreach ($applicableSignals as $signal) {
            $weight = self::SIGNAL_WEIGHTS[$signal] ?? 0;
            $totalWeight += $weight;

            $result = $this->evaluateSignal($signal, $data);

            $audit[] = [
                'signal' => $signal,
                'weight' => $weight,
                'passed' => $result['passed'],
                'tip' => $result['tip'],
            ];

            if ($result['passed']) {
                $passedWeight += $weight;
            }
        }

        $score = $totalWeight > 0 ? (int) round(($passedWeight / $totalWeight) * 100) : 0;
        $grade = GeoGrade::fromScore($score);

        return GeoProfile::updateOrCreate(
            [
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'locale' => $locale,
            ],
            [
                'score' => $score,
                'grade' => $grade,
                'audit' => $audit,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function evaluateSignal(string $signal, array $data): array
    {
        return match ($signal) {
            'has_json_ld' => $this->checkJsonLd($data),
            'has_faq_schema' => $this->checkFaqSchema($data),
            'entity_consistency' => $this->checkEntityConsistency($data),
            'has_geo_coordinates' => $this->checkGeoCoordinates($data),
            'has_review_schema' => $this->checkReviewSchema($data),
            'description_length' => $this->checkDescriptionLength($data),
            'has_specific_numbers' => $this->checkSpecificNumbers($data),
            'has_canonical' => $this->checkCanonical($data),
            'has_breadcrumb' => $this->checkBreadcrumb($data),
            'content_density' => $this->checkContentDensity($data),
            'has_opening_hours' => $this->checkOpeningHours($data),
            default => ['passed' => false, 'tip' => 'Unknown signal'],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkJsonLd(array $data): array
    {
        $passed = ($data['has_json_ld'] ?? false) === true;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add JSON-LD structured data and ensure translation exists for this locale',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkFaqSchema(array $data): array
    {
        $count = (int) ($data['faqs_count'] ?? 0);
        $passed = $count >= 1;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add at least one FAQ to enable FAQ schema',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkEntityConsistency(array $data): array
    {
        // Inline defaults on every geo.* read, because `mergeConfigFrom` is a
        // SHALLOW array_merge (ServiceProvider.php:168) — not recursive. An app
        // whose config/geo.php defines `scoring` with a single key replaces this
        // package's whole `scoring` array, and the unset reads come back null.
        // That fails silently rather than loudly: `$rating >= null` compares
        // against 0, so every model passes the signal and the scores are quietly
        // wrong. The defaults below match config/geo.php exactly.
        /** @var string $siteName */
        $siteName = config('geo.site_name', config('app.name', 'Laravel'));
        $name = (string) ($data['name'] ?? '');
        $content = (string) ($data['content'] ?? '');

        $haystack = mb_strtolower($name.' '.$content);
        $needle = mb_strtolower($siteName);

        $passed = str_contains($haystack, $needle);

        return [
            'passed' => $passed,
            'tip' => $passed ? null : "Include the brand name '{$siteName}' in the title or content",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkGeoCoordinates(array $data): array
    {
        $passed = isset($data['latitude'], $data['longitude']);

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add geographic coordinates (latitude and longitude)',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkReviewSchema(array $data): array
    {
        $rating = (float) ($data['rating'] ?? 0);
        $reviewCount = (int) ($data['review_count'] ?? 0);

        /** @var float $minRating */
        $minRating = config('geo.scoring.min_rating_for_signal', 4.0);

        /** @var int $minReviews */
        $minReviews = config('geo.scoring.min_reviews_for_signal', 10);

        $passed = $rating >= $minRating && $reviewCount >= $minReviews;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : "Need at least {$minReviews} reviews with rating >= {$minRating}",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkDescriptionLength(array $data): array
    {
        $content = strip_tags((string) ($data['content'] ?? ''));
        $wordCount = str_word_count($content);

        /** @var int $minWords */
        $minWords = config('geo.scoring.min_description_words', 150);

        $passed = $wordCount >= $minWords;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : "Description has {$wordCount} words, minimum is {$minWords}",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkSpecificNumbers(array $data): array
    {
        $price = (float) ($data['price'] ?? 0);
        $content = (string) ($data['content'] ?? '');

        $passed = $price > 0 || (bool) preg_match('/\d+/', $content);

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add specific numbers like prices, measurements, or statistics',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkCanonical(array $data): array
    {
        $url = (string) ($data['url'] ?? '');
        $passed = $url !== '';

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Ensure the model has a public URL',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkBreadcrumb(array $data): array
    {
        $passed = ($data['has_breadcrumb'] ?? false) === true;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add breadcrumb navigation data',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkContentDensity(array $data): array
    {
        $content = strip_tags((string) ($data['content'] ?? ''));

        $sentences = preg_split('/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $sentenceCount = is_array($sentences) ? count($sentences) : 0;

        preg_match_all('/\d+/', $content, $matches);
        $numberCount = count($matches[0]);

        $passed = $sentenceCount > 0 && ($numberCount / $sentenceCount) >= 0.5;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add more quantitative data (numbers, stats, measurements) to the content',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{passed: bool, tip: string|null}
     */
    private function checkOpeningHours(array $data): array
    {
        $openingHours = $data['opening_hours'] ?? [];
        $passed = is_array($openingHours) && count($openingHours) > 0;

        return [
            'passed' => $passed,
            'tip' => $passed ? null : 'Add opening hours information',
        ];
    }
}
