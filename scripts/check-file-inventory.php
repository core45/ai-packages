<?php

declare(strict_types=1);

/**
 * The inventory gate (plan §A.6).
 *
 * This is the check whose absence let 52 pipeline files vanish behind a green
 * test suite. A suite proves that what remains still works; it says nothing at
 * all about what stopped being there. Nothing else in CI does either — a
 * deleted resource, a dropped migration, an entire Filament sub-namespace that
 * never got copied across all leave every existing check green.
 *
 * So this compares the tracked file set under `packages/` against a committed
 * manifest and fails on drift:
 *
 *   - a path that disappeared          -> allowlist it with a reason, or revert
 *   - a path that appeared             -> refresh the manifest (`--update`)
 *
 * Failing on additions too is deliberate. A manifest that only tracks deletions
 * goes stale the moment anything is added, and a stale manifest cannot detect
 * the next deletion — the gate would report success while measuring nothing.
 * Refreshing is one command; that is the whole cost of keeping it honest.
 *
 * The manifest records all three captures §A.6 asks for: the sorted relative
 * paths, the per-directory counts, and the sorted migration basenames. The
 * paths are what the gate decides on. The other two exist so that a human
 * reading the diff of this file in a pull request can see the shape of what
 * changed without reconstructing it from a thousand-line path list.
 *
 * Usage:
 *   php scripts/check-file-inventory.php            # check, the CI mode
 *   php scripts/check-file-inventory.php --update   # rewrite the manifest
 *   php scripts/check-file-inventory.php --list     # print the inventory
 *
 * Exit codes: 0 clean, 1 drift, 2 could not run.
 */
$root = dirname(__DIR__);
$packagesDir = $root.'/packages';
$manifestPath = $root.'/scripts/file-inventory.json';
$allowlistPath = $root.'/scripts/file-inventory-allowlist.txt';

$update = in_array('--update', $argv, true);
$list = in_array('--list', $argv, true);

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Could not run: {$packagesDir} does not exist.\n");
    exit(2);
}

/**
 * The tracked paths under `packages/`, relative to the repository root.
 *
 * Deliberately `git ls-files` rather than a directory walk. A walk sees
 * `vendor/` trees from the per-package suites, `.phpunit.cache`, and whatever
 * else a local run left behind, so its "inventory" changes depending on who ran
 * what last — which is the opposite of what a gate needs. The tracked set is
 * the same on every machine and in CI, and it is also the only set a reviewer
 * can actually see in a diff.
 *
 * @return list<string>
 */
function trackedPackageFiles(string $root): array
{
    $command = 'git -C '.escapeshellarg($root).' ls-files -z -- packages';
    $output = shell_exec($command);

    if (!is_string($output)) {
        fwrite(STDERR, "Could not run: `git ls-files` produced no output. Is this a git checkout with git on PATH?\n");
        exit(2);
    }

    $paths = array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    sort($paths);

    return $paths;
}

/**
 * @param  list<string>  $paths
 * @return array{paths: list<string>, directory_counts: array<string, int>, migration_basenames: list<string>}
 */
function buildInventory(array $paths): array
{
    $directoryCounts = [];
    $migrationBasenames = [];

    foreach ($paths as $path) {
        $directory = dirname($path);
        $directoryCounts[$directory] = ($directoryCounts[$directory] ?? 0) + 1;

        // §A.6 point 3: basenames, not paths. Laravel's Migrator keys the
        // `migrations` ledger on the basename, so a migration that is moved
        // between packages is harmless while one that is retimestamped is a
        // different migration entirely — the old ledger row no longer matches
        // and it runs again on every existing install.
        if (str_contains($path, '/database/migrations/') && str_ends_with($path, '.php')) {
            $migrationBasenames[] = basename($path);
        }
    }

    ksort($directoryCounts);
    sort($migrationBasenames);

    return [
        'paths' => $paths,
        'directory_counts' => $directoryCounts,
        'migration_basenames' => $migrationBasenames,
    ];
}

/**
 * Removals that have been reviewed, as `path # reason`.
 *
 * A reason is required, not decorative: an entry without one is rejected. The
 * gate exists so that a removal is a decision someone recorded, and a bare path
 * records nothing that the deletion itself did not already say.
 *
 * @return array<string, string>
 */
function readAllowlist(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $allowed = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$entry, $reason] = array_pad(explode('#', $line, 2), 2, '');
        $allowed[trim($entry)] = trim($reason);
    }

    return $allowed;
}

$inventory = buildInventory(trackedPackageFiles($root));

if ($list) {
    echo "Inventory of packages/ (tracked files only)\n\n";

    foreach ($inventory['directory_counts'] as $directory => $count) {
        printf("  %5d  %s\n", $count, $directory);
    }

    printf("\n  %d files, %d migrations, %d directories\n\n",
        count($inventory['paths']),
        count($inventory['migration_basenames']),
        count($inventory['directory_counts']),
    );
}

if ($update) {
    $json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($manifestPath, $json."\n");

    printf("Manifest written: %d files, %d migrations.\n",
        count($inventory['paths']),
        count($inventory['migration_basenames']),
    );

    exit(0);
}

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Could not run: {$manifestPath} does not exist. Create it with `php scripts/check-file-inventory.php --update`.\n");
    exit(2);
}

try {
    /** @var array{paths: list<string>, migration_basenames: list<string>} $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "Could not run: {$manifestPath} is not valid JSON ({$e->getMessage()}).\n");
    exit(2);
}

$removed = array_values(array_diff($manifest['paths'], $inventory['paths']));
$added = array_values(array_diff($inventory['paths'], $manifest['paths']));
$allowlist = readAllowlist($allowlistPath);

$failed = false;

$unexplained = [];
$unreasoned = [];

foreach ($removed as $path) {
    if (!array_key_exists($path, $allowlist)) {
        $unexplained[] = $path;
    } elseif ($allowlist[$path] === '') {
        $unreasoned[] = $path;
    }
}

if ($unexplained !== []) {
    $failed = true;
    echo "\nFILES REMOVED WITHOUT REVIEW (".count($unexplained)."):\n\n";

    foreach ($unexplained as $path) {
        echo "  - {$path}\n";
    }

    echo "\n  Each of these was in the manifest and is no longer tracked.\n";
    echo "  Either revert the removal, or add the path to scripts/file-inventory-allowlist.txt\n";
    echo "  with a reason after a `#`, then re-run with --update.\n";
}

if ($unreasoned !== []) {
    $failed = true;
    echo "\nALLOWLISTED WITH NO REASON (".count($unreasoned)."):\n\n";

    foreach ($unreasoned as $path) {
        echo "  - {$path}\n";
    }

    echo "\n  A bare path records nothing the deletion did not already say.\n";
    echo "  Write why the removal is intended after the `#`.\n";
}

// Migration basenames are reported separately even though a removed migration
// already appears above. A retimestamped migration is a rename: the old
// basename leaves and a new one arrives, both of which look ordinary in a path
// diff, and the consequence — it runs again on every existing install, because
// the ledger row keyed on the old basename no longer matches — is invisible
// unless the basenames are compared as their own set.
$migrationsGone = array_values(array_diff($manifest['migration_basenames'], $inventory['migration_basenames']));
$migrationsNew = array_values(array_diff($inventory['migration_basenames'], $manifest['migration_basenames']));

if ($migrationsGone !== [] && $migrationsNew !== []) {
    echo "\nMIGRATION BASENAMES CHANGED — check this is not a retimestamp:\n\n";

    foreach ($migrationsGone as $basename) {
        echo "  gone:    {$basename}\n";
    }

    foreach ($migrationsNew as $basename) {
        echo "  arrived: {$basename}\n";
    }

    echo "\n  A migration that changed timestamp is a NEW migration to Laravel: the\n";
    echo "  ledger row keyed on the old basename no longer matches, so it runs again\n";
    echo "  on every install that already had it. Renaming across packages is fine;\n";
    echo "  changing the timestamp is not.\n";
}

if ($added !== []) {
    $failed = true;
    echo "\nMANIFEST IS OUT OF DATE (".count($added)." new files):\n\n";

    foreach (array_slice($added, 0, 20) as $path) {
        echo "  + {$path}\n";
    }

    if (count($added) > 20) {
        echo '  ... and '.(count($added) - 20)." more\n";
    }

    echo "\n  Run `php scripts/check-file-inventory.php --update` and commit the manifest.\n";
    echo "  A manifest that lags behind cannot detect the next deletion — it would\n";
    echo "  report success while measuring nothing.\n";
}

$stale = array_values(array_diff(array_keys($allowlist), $manifest['paths']));

if ($stale !== []) {
    // A warning, not a failure. Once an allowlisted removal is accepted into
    // the manifest its entry has done its job, and a stale entry is untidy
    // rather than dangerous — it can only ever excuse a path that is already
    // gone. Failing here would block work on somebody else's leftovers.
    echo "\nnote: ".count($stale).' allowlist entr'.(count($stale) === 1 ? 'y is' : 'ies are')." no longer needed and can be pruned:\n";

    foreach ($stale as $path) {
        echo "  - {$path}\n";
    }
}

if ($failed) {
    echo "\nRESULT: inventory drift.\n";
    exit(1);
}

printf("\nRESULT: inventory matches (%d files, %d migrations).\n",
    count($inventory['paths']),
    count($inventory['migration_basenames']),
);

exit(0);
