# core45/ai-core

The shared AI foundation every other `core45/ai-*` package depends on: the
remote AI client for the **phasea** service, its DTOs, contracts, enums and
exceptions, usage logging, the batch circuit breaker, the model registry, the
knowledge/learning system, async operation flows (detection + humanization),
prompt overrides, and the generic content-rewrite base class.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

## Requirements

PHP 8.4+, Laravel 13. Declared dependencies: `filament/filament`,
`astrotomic/laravel-translatable`, `laravel/scout`, `league/commonmark`,
`stevebauman/purify`, `ramsey/uuid`.

## Installation

Auto-discovered via `extra.laravel.providers`
(`Core45\AiCore\AiCoreServiceProvider`). No manual registration.

```bash
# optional — the provider already merges working defaults
php artisan vendor:publish --tag=ai-core-config
```

Set at minimum:

```dotenv
AI_SERVICE_BASE_URL=https://your-phasea-host
AI_SERVICE_TOKEN=...
```

### Upgrading an existing install

The `ai_*` migrations that ship here previously lived in the application's
`database/migrations/`. They kept their **original filenames**, so Laravel's
ledger already lists them and they will not re-run. Do not publish them into
your app and do not rename them.

One migration is new — `2026_08_24_152906_convert_ai_polymorphic_types_to_aliases`
— and normalizes `ai_operation_continuations.operation_type` to morph aliases.
Register the aliases before running it (see **Morph aliases** below).

## What this package provides

- **Client:** `Core45\AiCore\Contracts\AiClient` (bound by the consumer to
  `Services\RemoteAiClient`), `Services\AiClientFactory` for per-surface resolution.
- **Schema:** every `ai_*` table, loaded via `loadMigrationsFrom()`.
  Publish tag `ai-core-migrations` exists for apps that want them in their own
  tree — it is **not** the upgrade path.
- **Models:** conversations, messages, summaries, learned knowledge, knowledge
  gaps and conflicts, improvement suggestions, prompt overrides, content
  batches, detections, humanizations, operation continuations.
- **Filament:** `Core45\AiCore\Filament\AiCorePlugin` — register it on your
  panel (`->plugins([AiCorePlugin::make()])`) to get the knowledge, knowledge
  gap, conflict and prompt-override resources.
- **Rewrite base:** `Services\AbstractContentRewriteService` — domain packages
  subclass it and supply model resolution.

## What the host must supply

`ai-core` contains **no** `App\` imports and boots standalone. In exchange the
consuming app must bind these seams (see `App\Providers\AiServiceProvider` in
phaseb for a worked example):

| Contract | Purpose |
|---|---|
| `Contracts\AiClient` | usually `Services\RemoteAiClient` |
| `Contracts\IssuesGuestStreamTokens` | guest chat token brokering |
| `Contracts\EnrichesCitations` | GEO citation enrichment during rewrite |
| `Contracts\GeneratesSlugs` | slug generation for translated content |
| `Contracts\ChecksContentSimilarity` | duplicate-content checking |
| `Contracts\StoresFocusKeyphrases` | where SEO focus keyphrases are persisted |
| `Contracts\GeneratesContentMedia` | image/media generation for a rewritten record |
| `ModelRegistry` (singleton) | re-bind with your own model map — the package default is an EMPTY registry |

Config keys with **no package default** (unset = that flow is skipped):

| Key | Read by |
|---|---|
| `ai.finalize_job` | `Models\AIContentBatch::checkCompletion()` |
| `ai.analyze_feedback_job` | `Observers\AIConversationMessageObserver::updated()` |
| `ai.filament.navigation_group` | the Filament resources (defaults to the string `'AI'`) |
| `ai.factories.batchable_type` | `AIContentBatchFactory`, test fixtures only |

## Morph aliases (required, permanent)

`AiDetection` and `AiHumanization` are persisted by class name into
`ai_operation_continuations.operation_type`. Register the aliases in your app —
before running the conversion migration — and never remove them:

```php
Relation::morphMap([
    'ai_detection'    => \Core45\AiCore\Models\AiDetection::class,
    'ai_humanization' => \Core45\AiCore\Models\AiHumanization::class,
]);
```

`AiOperationContinuationService` reads and writes that column via
`getMorphClass()`, so the alias is what lands in the database and what the
`operation` morph relation matches.

## Testing

```bash
vendor/bin/phpunit -c packages/ai-core/phpunit.xml.dist
```

Orchestra Testbench, booting only this package's provider — no host app.
`tests/PackageWiringTest.php` covers container bindings and consumer-supplied
registry behaviour. Boundary enforcement lives host-side in
`tests/Architecture/AiExtractionBoundariesTest.php`; the rule for this package
is absolute: **zero `App\` imports**.

## Deploying

Queued job class names moved namespaces. Drain the queue before deploying —
see `docs/ai-extraction-phase6-release.md`.
