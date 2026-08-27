# scripts/

## check-file-inventory.php

The inventory gate (plan §A.6). Diffs the tracked file set under `packages/`
against `scripts/file-inventory.json` and fails on drift.

It exists because every other check in CI asks whether what is present is
correct, and none of them asks whether something stopped being present. That is
the failure this project actually had: 52 pipeline files went missing behind a
test suite that stayed green, because a suite can only exercise what remains.

```bash
php scripts/check-file-inventory.php            # check — this is what CI runs
php scripts/check-file-inventory.php --update   # rewrite the manifest
php scripts/check-file-inventory.php --list     # print per-directory counts
```

### The two ways it fails, and what to do

**A file was removed.** Either the removal was a mistake — revert it — or it was
intended, in which case add the path to `scripts/file-inventory-allowlist.txt`
with a reason after a `#`, then re-run with `--update`. A bare path with no
reason is rejected: the gate exists so that a removal is a decision somebody
recorded, and a bare path records nothing the deletion did not already say.

**A file was added.** Run `--update` and commit the manifest alongside the
change.

Failing on additions looks like friction and is not. A manifest that only
tracked deletions would go stale the moment anything was added, and a stale
manifest cannot detect the *next* deletion — the gate would keep reporting
success while measuring nothing.

### Why `git ls-files` and not a directory walk

A walk sees `vendor/` trees from the per-package suites, `.phpunit.cache`, and
whatever else the last local run left behind, so its idea of the inventory
depends on who ran what last. The tracked set is identical on every machine and
in CI, and it is also the only set a reviewer can see in a diff.

## split-packages.sh

Publishes each package under `packages/<name>` to its own read-only GitHub
repository (`core45/<name>`), the way `laravel/framework` publishes
`illuminate/*`. This is a one-way mirror: development happens only in this
monorepo. Nobody should ever commit to a split repo directly — every run of
this script rewrites the split branch from `packages/<name>`'s history here
and force-pushes it, so any commit made only in a split repo has no ancestor
in the monorepo and is destroyed the next time someone runs this script.

### Usage

```bash
# Split & push every package
./scripts/split-packages.sh

# Split & push only specific packages
./scripts/split-packages.sh ai-core ai-posts

# Also push a tag (refuses if the working tree is dirty)
./scripts/split-packages.sh --tag=v0.1.0
```

### Dry-run first — always

`--dry-run` does everything except the `git push`: it splits the subtree,
validates each `composer.json`, and prints exactly what *would* be pushed
and where. A plain run pushes for real, so treat dry-run as the default
habit, not an occasional extra step:

```bash
./scripts/split-packages.sh --dry-run
./scripts/split-packages.sh --dry-run --tag=v0.1.0
```

Read the printed summary table before dropping `--dry-run` for a live run.

### Tags are what Composer actually resolves

A branch push alone (`refs/heads/main` in each split repo) is not enough to
make a package installable. Composer resolves version constraints like
`core45/ai-posts: ^0.1` against **tags**, not branch history. A package is
not consumable by `composer require` until a version tag has been pushed to
its split repo — so any release that should be installable needs
`--tag=vX.Y.Z`.

### Preflight checks (abort loudly, before touching anything)

- Working tree must be clean (`git status --porcelain` empty).
- `git remote get-url origin` must resolve.
- Every `packages/<name>/composer.json` must exist and declare
  `"name": "core45/<name>"` — a mismatch is a hard failure, since a split
  repo receiving the wrong package identity is worse than skipping it.
- `--tag` refuses to run against a dirty working tree.

### If a split repo doesn't exist yet

This script never creates GitHub repositories. If a push fails because the
target repo is missing, it prints the exact command to create it, e.g.:

```bash
gh repo create core45/ai-posts --private --description "Read-only split of core45/ai-packages: ai-posts"
```

Create the repo, then re-run the script.

### Why plain `git subtree split`, not `splitsh-lite`

`splitsh-lite` is faster, but it is a separate binary that is not installed
in this environment. Plain `git subtree split` (bundled with git) produces
an equivalent result — a rewritten history scoped to `packages/<name>` — at
this repo's size the extra time is a small, acceptable trade for not
depending on an unavailable tool.
