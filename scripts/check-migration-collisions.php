<?php

declare(strict_types=1);

/*
 * Migration collision check.
 *
 * Laravel's Migrator keys the `migrations` ledger on BASENAME, not path. Two
 * consequences drive everything here, and both fail silently rather than loudly:
 *
 *  1. Two files with the same basename in different packages are one ledger row.
 *     Whichever the Migrator reaches first runs; the second is recorded as
 *     already run and never executes. Its schema change simply never happens.
 *
 *  2. Two files with DIFFERENT basenames that both `Schema::create` the same
 *     table are two ledger rows. Both run, and the second throws "table already
 *     exists" on a fresh install — while an existing install, where only one of
 *     them was ever recorded, upgrades fine. So it passes in the place you test
 *     and breaks in the place you ship.
 *
 * The existing scripts/verify-packages-are-dropin.sh in the host app checks that
 * basenames have not CHANGED against a baseline, and that each model's table is
 * created by a migration in its own package. Neither of those catches case 2 —
 * which is exactly the case the 00bb merge produced, with
 * `content_similarity_checks` created by two differently-timestamped files.
 *
 * Exit codes: 0 clean, 1 collision found, 2 could not run.
 */

$root = dirname(__DIR__);
$packagesDir = $root.'/packages';

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Cannot find packages/ under {$root}\n");
    exit(2);
}

/** @var array<string, list<array{package: string, path: string}>> $byBasename */
$byBasename = [];

/** @var array<string, list<array{package: string, basename: string}>> $creators */
$creators = [];

/** @var list<array{package: string, basename: string, table: string}> $rows */
$rows = [];

$packages = array_values(array_filter(
    scandir($packagesDir) ?: [],
    static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($packagesDir.'/'.$entry)
));
sort($packages);

foreach ($packages as $package) {
    $migrationsDir = $packagesDir.'/'.$package.'/database/migrations';

    if (!is_dir($migrationsDir)) {
        continue;
    }

    $files = glob($migrationsDir.'/*.php') ?: [];
    sort($files);

    foreach ($files as $path) {
        $basename = basename($path);
        $byBasename[$basename][] = ['package' => $package, 'path' => $path];

        $source = (string) file_get_contents($path);

        // Only `Schema::create` matters. `Schema::table` alters an existing
        // table and is expected to appear many times for one table.
        if (preg_match_all("/Schema::create\(\s*'([^']+)'/", $source, $matches) === 0) {
            continue;
        }

        foreach (array_unique($matches[1]) as $table) {
            $creators[$table][] = ['package' => $package, 'basename' => $basename];
            $rows[] = ['package' => $package, 'basename' => $basename, 'table' => $table];
        }
    }
}

$duplicateBasenames = array_filter($byBasename, static fn (array $entries): bool => count($entries) > 1);
$duplicateCreates = array_filter($creators, static fn (array $entries): bool => count($entries) > 1);

// `--list` prints the full table -> migration map. The consolidation plan asked
// for that map as a written inventory; generating it on demand means it cannot
// go stale the way a checked-in copy would.
if (in_array('--list', $argv, true)) {
    ksort($creators);

    echo "Table ownership map\n";
    echo str_repeat('=', 72)."\n";

    foreach ($creators as $table => $entries) {
        foreach ($entries as $entry) {
            printf("  %-38s %-18s %s\n", $table, $entry['package'], $entry['basename']);
        }
    }

    echo "\n";
}

echo "Migration inventory\n";
echo str_repeat('=', 72)."\n";
printf("  packages with migrations : %d\n", count(array_unique(array_column($rows, 'package'))));
printf("  migration files          : %d\n", array_sum(array_map('count', $byBasename)));
printf("  tables created           : %d\n", count($creators));
echo "\n";

$failed = false;

echo "Check 1 — no two migration files share a basename\n";
echo str_repeat('-', 72)."\n";

if ($duplicateBasenames === []) {
    echo "  PASS\n";
} else {
    $failed = true;

    foreach ($duplicateBasenames as $basename => $entries) {
        echo "  FAIL  {$basename}\n";

        foreach ($entries as $entry) {
            echo "          {$entry['package']}\n";
        }

        echo "        One ledger row for both. The second never runs, and nothing reports it.\n";
    }
}

echo "\n";
echo "Check 2 — no table is created by two different migrations\n";
echo str_repeat('-', 72)."\n";

if ($duplicateCreates === []) {
    echo "  PASS\n";
} else {
    $failed = true;

    foreach ($duplicateCreates as $table => $entries) {
        echo "  FAIL  table '{$table}' is created by:\n";

        foreach ($entries as $entry) {
            echo "          {$entry['package']}/database/migrations/{$entry['basename']}\n";
        }

        echo "        Two ledger rows, both run. A fresh install throws\n";
        echo "        \"table already exists\"; an existing install upgrades fine,\n";
        echo "        so this passes where you test it and breaks where you ship.\n";
    }
}

echo "\n";

if ($failed) {
    echo "RESULT: collisions found.\n";
    exit(1);
}

echo "RESULT: no collisions.\n";
exit(0);
