# core45 AI packages

Development monorepo for five Composer packages. Each is published as its own
read-only split repository, so consumers install them individually:

```bash
composer require core45/ai-posts
```

This root package is never published.

## The packages

| Package | What it provides | Migrations | Filament |
| --- | --- | --- | --- |
| `core45/ai-core` | Shared AI foundation: remote AI client, DTOs, contracts, usage logging, batch circuit breaker, `ModelRegistry`, knowledge/learning system, async operation flows, generic content-rewrite base | ships the `ai_*` tables | `filament/filament` |
| `core45/ai-posts` | Blog AI stack: BlogTopics research and generation, BlogPipeline with modules and quality gates, post rewriting, translation, photo generation, link enrichment | ships the blog domain schema | `filament/notifications`, `filament/support` |
| `core45/ai-chat` | Customer-facing chat transport: `CustomerAIService`, guest stream token brokering, Livewire chat panel, SSE proxy controller, conversation summariser | none | none |
| `core45/ai-products` | Product content generation and rewriting | none | `filament/notifications` |
| `core45/ai-webinars` | Webinar content generation and rewriting | none | `filament/notifications` |

Four packages depend on `ai-core`; `ai-core` depends on none of them. That
one-to-four fan-out is why these live in a single repository: a change to
`ai-core` would otherwise cost five pull requests and could not be tested
against its dependents until it was tagged and published.

## Supported versions

| | Constraint |
| --- | --- |
| PHP | `^8.4` |
| Laravel | `^13.26.1` |
| Filament | `^5.7.6` |

The narrowest set satisfying every package plus the primary consuming
application. Filament behaviour is verified against **5.7.6 specifically**.

## The rule that makes these installable anywhere

**No package imports an `App\` class.** Host models are reached through
`ModelRegistry`, and host behaviour through the capability contracts in each
package's `src/Contracts/`. A consuming application binds those contracts to its
own implementations.

This is enforced, not merely documented: `PackageWiringTest` asserts the host
import count is exactly zero. If a host dependency is genuinely needed, the
answer is a new contract, never a raised ceiling.

## Local development

```bash
composer install
vendor/bin/pest              # all package suites
vendor/bin/pint              # formatting
vendor/bin/phpstan analyse   # static analysis
```

The root `autoload` block maps all five packages, so tests run without path
repositories.

## Consuming from a private repository

These repositories are private, so they are not on public Packagist. Each
consuming application needs a `vcs` entry per package plus a GitHub token in
`auth.json` — and so does CI, and every deploy target. Verify with a clean
checkout and no local Composer cache before considering an application migrated.

Pin real version constraints. `"core45/ai-core": "*"` is harmless against a
`path` repository but accepts any future breaking major from a tagged one.
