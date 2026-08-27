<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `ai-core` is the domain package: it installs into apps with no Filament, or
 * on a different panel entirely, so it may not import Filament.
 *
 * This existed only as a grep in a runbook, which is to say it existed only for
 * as long as someone remembered to run it. D7 was a live opportunity to break
 * it — the grade enum arrived from a host where it was declared
 * `implements HasLabel`, and copying that in would have made
 * `filament/support` a hard requirement of a headless package.
 */
class FilamentIndependenceTest extends TestCase
{
    /**
     * The one file allowed to import Filament, and why.
     *
     * `AIImprovementAppliedNotification` builds a Filament database payload
     * ONLY after `class_exists()` says Filament is installed, and falls back to
     * a plain notification otherwise — so the imports are unresolved symbols in
     * a headless app, which costs nothing, rather than a dependency.
     *
     * An exact list, not a ceiling: a ceiling satisfied at one keeps passing
     * when a second file appears.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'Notifications/AIImprovementAppliedNotification.php',
    ];

    #[Test]
    public function only_the_optional_notification_imports_filament(): void
    {
        $root = __DIR__.'/../src';
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (!preg_match('/^use\s+Filament\\\\/m', $source)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (!in_array($relative, self::ALLOWED, true)) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "ai-core must stay installable without Filament. Move the UI to ai-core-filament:\n".implode("\n", $offenders),
        );
    }

    /**
     * A guard on the guard: if the scan stopped finding the file it is supposed
     * to tolerate — renamed, moved, or the walk silently matching nothing — the
     * assertion above would pass vacuously.
     */
    #[Test]
    public function the_allowlisted_file_is_still_there(): void
    {
        foreach (self::ALLOWED as $relative) {
            $this->assertFileExists(__DIR__.'/../src/'.$relative);
        }
    }
}
