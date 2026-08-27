<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The five domain packages each carry a `PackageWiringTest` that fails on an
 * `App\` import from inside the package. The two Filament packages carried
 * nothing equivalent, so the only thing standing between them and a host import
 * was the monorepo's CI step — which does not exist in the split repository this
 * package is published to.
 *
 * Being a UI package changes what is allowed to be imported, not whether the
 * host may be. Filament is this package's whole purpose and is imported freely;
 * `App\` is exactly as forbidden here as in ai-core.
 */
class HostCouplingTest extends TestCase
{
    /**
     * The host classes this package may import: none.
     *
     * An exact assertion, not a ceiling. A ceiling satisfied at zero keeps
     * passing when the imports come back; an exact match does not. If one is
     * genuinely needed, the answer is a contract in `ai-core/src/Contracts/`,
     * not an entry added here.
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_IMPORTS = [];

    #[Test]
    public function the_package_imports_no_host_classes(): void
    {
        $this->assertSame(
            self::ALLOWED_HOST_IMPORTS,
            $this->hostImportsUnder(__DIR__.'/../src'),
            'ai-core-filament imported an App\\ class. Route it through ModelRegistry or a contract in ai-core/src/Contracts/.',
        );
    }

    /**
     * A UI package is the likeliest place for a host reference to survive as a
     * string rather than an import — a morph type, a policy lookup, a resource's
     * `$model`. `^use App\` never sees those.
     */
    #[Test]
    public function the_package_names_no_host_classes_as_strings(): void
    {
        $found = [];

        foreach ($this->phpFilesUnder(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // Strip comments first: several docblocks in these packages name a
            // host class precisely to explain why the code does NOT touch it,
            // and flagging those would make the gate cry wolf until someone
            // deleted it.
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            preg_match_all('/[\'"]\\\\?App\\\\\\\\[A-Za-z0-9_\\\\\\\\]+[\'"]/', $code, $matches);
            $found = array_merge($found, $matches[0]);
        }

        $found = array_values(array_unique($found));
        sort($found);

        $this->assertSame([], $found, 'ai-core-filament names an App\\ class as a string literal.');
    }

    /**
     * @return list<string>
     */
    private function hostImportsUnder(string $directory): array
    {
        $found = [];

        foreach ($this->phpFilesUnder($directory) as $file) {
            preg_match_all(
                '/^use\s+(App\\\\[^\s;]+);/m',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            $found = array_merge($found, $matches[1]);
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * No component may hand an enum class straight to `->options()`.
     *
     * The twin of the same gate in ai-posts-filament. This package is already
     * clean — every `->options()` here takes an array or a method call — so this
     * test starts green and stays that way. That is the point: the failure mode
     * is silent. Filament does not throw when the enum lacks `HasLabel`, it
     * falls back to `$case->name` (filament/tables
     * `Filters/Concerns/HasOptions.php`), so a filter renders `InProgress`
     * instead of the translated label while every other test still passes.
     *
     * The packaged enums deliberately do not implement `HasLabel`: it lives in
     * `filament/support`, which the headless packages must not depend on.
     */
    #[Test]
    public function no_component_passes_an_enum_class_to_options(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // `//[^\n]*`, never `//.*$` — under /s the dot matches newlines, so
            // the latter swallows the file from its first line comment onwards.
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            if (preg_match('/->options\(\s*[A-Za-z_\\\\]+::class\s*\)/', $code) === 1) {
                $offenders[] = basename((string) $file->getPathname());
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'A component passed an enum class to ->options(). The packaged enums do not '
            .'implement Filament\\Support\\Contracts\\HasLabel, so Filament silently renders '
            .'raw case names. Map ->cases() to ->getLabel() explicitly instead.',
        );
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
