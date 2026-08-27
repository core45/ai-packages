#!/usr/bin/env bash
#
# split-packages.sh — publish packages/<name> subtrees to their own
# read-only GitHub repositories (core45/<name>), the way Laravel splits
# illuminate/* out of laravel/framework.
#
# WHY THIS EXISTS
# ----------------
# Development only ever happens in THIS monorepo. The split repos
# (github.com/core45/ai-core, core45/ai-posts, ...) are generated mirrors
# that Composer resolves `core45/ai-posts: ^0.1` against. Nobody should
# ever commit directly to a split repo: this script rewrites each split
# branch from the monorepo's packages/<name> history every time it runs,
# so a commit made only in a split repo has no ancestor here and is
# silently destroyed (force-pushed over) the next time this script runs.
# If someone needs to change a package, the change belongs in
# packages/<name> in THIS repo, not in the split mirror.
#
# WHY `git subtree split` AND NOT splitsh-lite
# ---------------------------------------------
# splitsh-lite is the fast (Go, near-instant) way to do this split, but it
# is a separate binary that is NOT installed in this environment. Plain
# `git subtree split` ships with git itself and produces an equivalent
# result: a linear history containing only the commits that touched
# packages/<name>, rewritten with paths relative to that directory. It is
# meaningfully slower on large histories/repos (it walks and rewrites
# every commit touching the prefix, rather than using splitsh-lite's
# packfile-level tricks), but this monorepo is small enough that the
# extra minutes are an acceptable trade for not depending on an
# unavailable binary.
#
# USAGE
#   scripts/split-packages.sh [--dry-run] [--tag=vX.Y.Z] [package ...]
#
# With no package names, every package under packages/ is split & pushed.
# See scripts/README.md for the recommended dry-run-first workflow.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

ORG="core45"
PACKAGES_DIR="packages"
# SSH by default. Set SPLIT_REMOTE_PREFIX to push over https instead, e.g.
#   SPLIT_REMOTE_PREFIX="https://github.com/core45" ./scripts/split-packages.sh
# which is what a machine authenticated with `gh` but without a GitHub SSH key
# needs — `ssh -T git@github.com` returning "Permission denied (publickey)" is
# the fingerprint.
REMOTE_PREFIX="${SPLIT_REMOTE_PREFIX:-git@github.com:${ORG}}"
SPLIT_BRANCH_PREFIX="split"             # local branch used to hold each split, e.g. split/ai-posts

DRY_RUN=0
TAG=""
REQUESTED_PACKAGES=()

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

for arg in "$@"; do
    case "$arg" in
        --dry-run)
            DRY_RUN=1
            ;;
        --tag=*)
            TAG="${arg#--tag=}"
            ;;
        --help|-h)
            sed -n '1,40p' "$0"
            exit 0
            ;;
        --*)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
        *)
            REQUESTED_PACKAGES+=("$arg")
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Repo root / preflight
# ---------------------------------------------------------------------------

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "==================================================================="
    echo " MODE: DRY RUN — nothing will be pushed. This is the safe path."
    echo "==================================================================="
else
    echo "==================================================================="
    echo " MODE: LIVE — this WILL push to real GitHub repositories."
    echo "==================================================================="
fi

# 1. Working tree must be clean.
if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: working tree is not clean. Commit or stash your changes before splitting." >&2
    git status --short >&2
    exit 1
fi

# 2. State the current branch (informational, but required by spec).
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
echo "Current branch: ${CURRENT_BRANCH}"

# 3. origin must resolve.
if ! ORIGIN_URL="$(git remote get-url origin 2>/dev/null)"; then
    echo "ERROR: 'git remote get-url origin' failed — no origin remote configured." >&2
    exit 1
fi
echo "Origin: ${ORIGIN_URL}"

# 4. Refuse to tag against a dirty tree.
#    (Redundant with the check above today, but kept explicit: if a future
#    edit relaxes the "clean tree" preflight for non-tagging runs, tagging
#    must still hard-refuse on dirt, because a tag is a permanent pointer
#    and should never be made to point at an ambiguous state.)
if [[ -n "$TAG" ]] && [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: refusing to tag '${TAG}' — working tree is dirty." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Discover valid packages
# ---------------------------------------------------------------------------

VALID_PACKAGES=()
for dir in "${PACKAGES_DIR}"/*/; do
    [[ -d "$dir" ]] || continue
    VALID_PACKAGES+=("$(basename "$dir")")
done

if [[ "${#VALID_PACKAGES[@]}" -eq 0 ]]; then
    echo "ERROR: no packages found under ${PACKAGES_DIR}/." >&2
    exit 1
fi

is_valid_package() {
    local candidate="$1"
    local p
    for p in "${VALID_PACKAGES[@]}"; do
        [[ "$p" == "$candidate" ]] && return 0
    done
    return 1
}

if [[ "${#REQUESTED_PACKAGES[@]}" -eq 0 ]]; then
    PACKAGES=("${VALID_PACKAGES[@]}")
else
    PACKAGES=()
    UNKNOWN=()
    for name in "${REQUESTED_PACKAGES[@]}"; do
        if is_valid_package "$name"; then
            PACKAGES+=("$name")
        else
            UNKNOWN+=("$name")
        fi
    done

    if [[ "${#UNKNOWN[@]}" -gt 0 ]]; then
        echo "ERROR: unknown package(s): ${UNKNOWN[*]}" >&2
        echo "Valid packages are: ${VALID_PACKAGES[*]}" >&2
        exit 1
    fi
fi

echo "Packages to process: ${PACKAGES[*]}"
echo

# ---------------------------------------------------------------------------
# Per-package split + push
# ---------------------------------------------------------------------------

# Summary rows: "name|sha|target|status"
SUMMARY=()

for name in "${PACKAGES[@]}"; do
    prefix="${PACKAGES_DIR}/${name}"
    target_repo="${REMOTE_PREFIX}/${name}.git"
    composer_json="${prefix}/composer.json"

    echo "-------------------------------------------------------------"
    echo "Package: ${name}"

    # 6. Sanity-check composer.json exists and its "name" matches core45/<name>.
    #    A mismatch here means the split repo would receive a package
    #    claiming to be something it is not — Composer would resolve it
    #    under the wrong identity, or a consumer's composer.json constraint
    #    would silently point at the wrong code. That is worse than not
    #    splitting at all, so it is a hard failure, not a warning.
    if [[ ! -f "$composer_json" ]]; then
        echo "ERROR: ${composer_json} does not exist." >&2
        exit 1
    fi

    declared_name="$(php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($data) || !isset($data["name"])) {
            fwrite(STDERR, "no \"name\" field in " . $argv[1] . PHP_EOL);
            exit(1);
        }
        echo $data["name"];
    ' "$composer_json")"

    expected_name="${ORG}/${name}"
    if [[ "$declared_name" != "$expected_name" ]]; then
        echo "ERROR: ${composer_json} declares name '${declared_name}', expected '${expected_name}'." >&2
        echo "Refusing to split — a split repo receiving the wrong package identity is worse than no split." >&2
        exit 1
    fi
    echo "composer.json name OK: ${declared_name}"

    # 1. Split the subtree into a commit-bearing branch.
    #    `git subtree split` walks the whole history rewriting paths; on
    #    this repo's size that is seconds to low minutes per package, not
    #    the near-instant splitsh-lite would give — see header comment.
    split_branch="${SPLIT_BRANCH_PREFIX}/${name}"
    echo "Running: git subtree split --prefix=${prefix} -b ${split_branch} (this may take a while)"

    # Recreate the local split branch fresh each run — it is a scratch
    # artifact of this script, not something to accumulate across runs.
    git branch -D "$split_branch" >/dev/null 2>&1 || true

    split_sha="$(git subtree split --prefix="$prefix" -b "$split_branch")"
    short_sha="${split_sha:0:7}"
    echo "Split commit: ${short_sha} (branch ${split_branch})"

    status="skipped"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "[dry-run] Would push: ${split_sha} -> ${target_repo} (refs/heads/main)"
        if [[ -n "$TAG" ]]; then
            echo "[dry-run] Would push tag: ${TAG} -> ${target_repo}"
        fi
        status="dry-run"
    else
        echo "Pushing ${split_branch} (${short_sha}) -> ${target_repo}"

        push_output=""
        push_failed=0
        if ! push_output="$(git push "$target_repo" "${split_branch}:refs/heads/main" 2>&1)"; then
            push_failed=1
        fi

        if [[ "$push_failed" -eq 1 ]]; then
            echo "$push_output" >&2
            # A missing repo shows up as a "repository not found" style
            # error from GitHub. Detect it and hand back a precise remedy
            # instead of leaving the operator to decode a raw git error.
            if echo "$push_output" | grep -qiE "repository not found|does not exist|could not read from remote"; then
                echo
                echo "REMEDY: the split repository does not exist yet. This script never" >&2
                echo "creates repositories. Create it, then re-run:" >&2
                echo "    gh repo create ${ORG}/${name} --private --description \"Read-only split of ${ORG}/ai-packages: ${name}\"" >&2
            else
                echo "ERROR: push to ${target_repo} failed for an unrecognised reason; see output above." >&2
            fi
            status="failed"
            SUMMARY+=("${name}|${short_sha}|${target_repo}|${status}")
            echo
            continue
        fi

        status="pushed"

        if [[ -n "$TAG" ]]; then
            echo "Pushing tag ${TAG} -> ${target_repo} (pointing at ${short_sha})"
            if ! tag_output="$(git push "$target_repo" "${split_sha}:refs/tags/${TAG}" 2>&1)"; then
                echo "$tag_output" >&2
                echo "ERROR: failed to push tag ${TAG} to ${target_repo}." >&2
                status="pushed (tag failed)"
            fi
        fi
    fi

    SUMMARY+=("${name}|${short_sha}|${target_repo}|${status}")
    echo
done

# ---------------------------------------------------------------------------
# Summary table
# ---------------------------------------------------------------------------

echo "==================================================================="
echo "Summary"
echo "==================================================================="
printf "%-20s %-10s %-35s %s\n" "PACKAGE" "SHA" "TARGET" "STATUS"
for row in "${SUMMARY[@]}"; do
    IFS='|' read -r name sha target status <<< "$row"
    printf "%-20s %-10s %-35s %s\n" "$name" "$sha" "$target" "$status"
done
