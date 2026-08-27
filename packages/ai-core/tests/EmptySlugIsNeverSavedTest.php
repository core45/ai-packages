<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Services\AbstractContentRewriteService;
use Core45\AiCore\Support\Slugs\StrSlugGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

/**
 * The package default for `GeneratesSlugs` must not be able to blank a live URL.
 *
 * `StrSlugGenerator` is `Str::slug()`, and `Str::slug()` returns `''` for any
 * title with nothing transliterable in it — every CJK and Thai title. Cyrillic,
 * Greek and Arabic come through fine, which is why this is invisible across most
 * of Europe and total in Japan.
 *
 * Every caller of `generateUniqueSlug()` writes the return value straight into a
 * translation and saves it (twelve call sites across ai-posts, ai-products and
 * ai-webinars). So an unguarded empty return does not fail — it silently
 * replaces the public URL of a published post with an empty string, and hands
 * the next colliding record the slug `-1`.
 *
 * This is a regression the defaults introduced, not a pre-existing bug: before
 * `GeneratesSlugs` had a default binding, `app(GeneratesSlugs::class)` threw
 * `BindingResolutionException`, every call site caught it into
 * `$results['errors']`, and no write happened. The guard restores exactly that
 * — which is why the assertion below is about the exception being a plain
 * `Exception` subclass, not merely about it being thrown.
 */
class EmptySlugIsNeverSavedTest extends TestCase
{
    /**
     * The premise, pinned. If a future Laravel teaches `Str::slug()` to
     * transliterate Japanese, this test says so instead of quietly protecting
     * against nothing.
     */
    #[Test]
    public function the_default_generator_really_does_return_nothing_for_these_scripts(): void
    {
        $generator = new StrSlugGenerator;

        foreach (['日本語のタイトル', '한국어 제목', 'ทดสอบ'] as $title) {
            $this->assertSame('', $generator->make($title), $title);
        }

        // The contrast that makes this a script problem rather than a broken
        // generator: these do transliterate.
        $this->assertSame('privet-mir', $generator->make('Привет мир'));
        $this->assertSame('normal-title', Str::slug('Normal Title'));
    }

    #[Test]
    public function an_empty_slug_throws_instead_of_being_returned_for_saving(): void
    {
        $service = $this->serviceUnderTest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty slug/');

        $this->generateUniqueSlug($service, '日本語のタイトル', 'ja');
    }

    /**
     * The thrown type matters as much as the throw. Every call site catches
     * `\Exception` and records the failure; a `\Error` or a bare `\Throwable`
     * would escape those handlers and fail the whole run instead of one field.
     */
    #[Test]
    public function the_failure_is_catchable_by_the_call_sites_error_handling(): void
    {
        $service = $this->serviceUnderTest();

        try {
            $this->generateUniqueSlug($service, 'ทดสอบ', 'th');
            $this->fail('Expected an empty slug to be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString(GeneratesSlugs::class, $e->getMessage());
            $this->assertStringContainsString('th', $e->getMessage());
        }
    }

    #[Test]
    public function a_title_the_generator_can_slug_is_unaffected(): void
    {
        $this->assertSame(
            'normal-title',
            $this->generateUniqueSlug($this->serviceUnderTest(), 'Normal Title', 'en'),
        );
    }

    private function serviceUnderTest(): AbstractContentRewriteService
    {
        return new SlugOnlyRewriteService;
    }

    private function generateUniqueSlug(AbstractContentRewriteService $service, string $title, string $locale): string
    {
        $method = new ReflectionMethod($service, 'generateUniqueSlug');

        return (string) $method->invoke($service, $title, 0, $locale);
    }
}

/**
 * A real subclass rather than a PHPUnit double.
 *
 * `generateUniqueSlug()` is the method under test, so it must NOT be replaced,
 * and doubling an abstract class to keep one concrete method is exactly the
 * shape PHPUnit 13 warns about. `slugExistsForLocale()` returning false is the
 * honest first-row case: no collision, so the uniqueness loop does not run and
 * the guard is the only thing between the generator and the return value.
 */
final class SlugOnlyRewriteService extends AbstractContentRewriteService
{
    protected function slugExistsForLocale(string $slug, int $excludeId, string $locale): bool
    {
        return false;
    }

    protected function getContentFields(): array
    {
        return [];
    }

    protected function getSeoFields(): array
    {
        return [];
    }

    protected function getModelClass(): string
    {
        return Model::class;
    }

    protected function getTranslationClass(): string
    {
        return Model::class;
    }

    protected function getServiceName(): string
    {
        return 'SlugOnlyRewriteService';
    }

    protected function getContentTypeLabel(): string
    {
        return 'test';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'test';
    }

    protected function getSeoPromptOverrideKey(): string
    {
        return 'test.seo';
    }

    protected function getContentTypeKey(): string
    {
        return 'test';
    }

    protected function buildModelContext(Model $model): array
    {
        return [];
    }

    protected function getFieldDescription(string $field): string
    {
        return $field;
    }

    protected function buildSeoContext(Model $model, string $locale, array $context): array
    {
        return $context;
    }

    protected function handleNameSlug(Model $model, string $locale, array $context): void {}

    protected function findSourceLocale(Model $model, string $field): ?string
    {
        return null;
    }

    protected function resolveArchetype(Model $model): ?Model
    {
        return null;
    }
}
