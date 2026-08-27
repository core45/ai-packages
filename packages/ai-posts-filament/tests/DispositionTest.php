<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * The disposition gate: every Filament tree the plan's §3 matrix put in this
 * package is actually here.
 *
 * This exists because of a specific miss. §3 scopes bucket (a) by category —
 * "the 9 in-scope models **and their Filament sub-namespaces**" — while D23
 * dispositions by name and passed over `BlogProductSeries` in silence. The
 * models moved, the checklist item read "bucket (a) moved and renamespaced",
 * and it was true of the conspicuous half. The Filament tree sat in the host
 * for two phases with nothing measuring the gap.
 *
 * Nothing else could have caught it. The boundary ratchets count imports, and a
 * tree that never arrived imports nothing. The inventory gate watches
 * `packages/` for files that *leave*, and this tree never left anywhere — the
 * host simply kept it. Every test in this package passed, because a suite can
 * only exercise what is present.
 *
 * So the assertion runs the other way round: not "is what is here correct" but
 * "is everything the matrix named here at all". The counts below are the
 * matrix's own, recorded when the disposition was made.
 */
class DispositionTest extends TestCase
{
    /**
     * The Filament resource trees §3 bucket (a) assigns to this package, with
     * the file count the matrix recorded for each.
     *
     * The count is a floor, not an equality. The failure this guards against is
     * a tree arriving half-copied or not at all; a tree growing a page later is
     * ordinary work and must not fail CI. An equality here would be a tripwire
     * for the wrong thing.
     *
     * The resource class is spelled out rather than derived from the directory
     * name. Filament's convention is a plural directory holding a singular
     * class, and naive singularisation gets that wrong in this very list:
     * `BlogTopicBatches` is `BlogTopicBatchResource`, not `BlogTopicBatche…`,
     * while `BlogProductSeries` is already singular and must not be trimmed at
     * all. A gate whose own naming rule is guesswork fails on itself.
     *
     * @var array<string, array{files: int, class: string}>
     */
    private const MATRIX_RESOURCES = [
        'BlogTopicProfiles' => ['files' => 8, 'class' => 'BlogTopicProfileResource'],
        'BlogTopicBatches' => ['files' => 6, 'class' => 'BlogTopicBatchResource'],
        'BlogProductSeries' => ['files' => 7, 'class' => 'BlogProductSeriesResource'],
        'ContentModules' => ['files' => 6, 'class' => 'ContentModuleResource'],
        'BlogExternalLinks' => ['files' => 8, 'class' => 'BlogExternalLinkResource'],
    ];

    #[Test]
    public function every_resource_tree_the_matrix_assigned_here_is_present(): void
    {
        $missing = [];
        $short = [];

        foreach (self::MATRIX_RESOURCES as $resource => $expected) {
            $directory = __DIR__.'/../src/Resources/'.$resource;

            if (!is_dir($directory)) {
                $missing[] = $resource;

                continue;
            }

            $count = count($this->phpFilesUnder($directory));

            if ($count < $expected['files']) {
                $short[] = "{$resource} ({$count} files, matrix recorded {$expected['files']})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'A Filament tree that §3 bucket (a) assigns to ai-posts-filament is not here. '
            .'It is most likely still host-owned: check app/Filament/Resources/ in the host '
            .'application before assuming the matrix is wrong.',
        );

        $this->assertSame(
            [],
            $short,
            'A Filament tree assigned here has fewer files than the disposition matrix '
            .'recorded, which usually means a partial copy — a Resource without its Pages, '
            .'or a tree copied before its RelationManagers.',
        );
    }

    /**
     * Each tree must also expose the Resource class itself.
     *
     * A directory that exists and holds the right number of files can still be
     * unreachable: the count says nothing about whether the class Filament
     * actually routes to is one of them.
     */
    #[Test]
    public function every_matrix_resource_class_exists_in_this_namespace(): void
    {
        foreach (self::MATRIX_RESOURCES as $resource => $expected) {
            $class = 'Core45\\AiPosts\\Filament\\Resources\\'.$resource.'\\'.$expected['class'];

            $this->assertTrue(
                class_exists($class),
                "{$class} does not exist. The {$resource} tree is present but the resource "
                .'class Filament routes to is missing or still in the host namespace.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
