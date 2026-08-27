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

## How a host application integrates this

`ai-core` contains **no** `App\` imports and boots standalone (see
`App\Providers\AiServiceProvider` in phaseb for a worked example of every
binding below).

### Contracts the host must bind

Everything in `Core45\AiCore\Contracts\` (`packages/ai-core/src/Contracts/`).
`AiCoreServiceProvider::register()` binds only two of them with a default;
resolving any of the rest with nothing bound throws a
`BindingResolutionException`/`Error` naming the interface at the moment that
code path runs — not at boot.

| Contract | For | Bound by the package? |
|---|---|---|
| `AiClient` | the single task-oriented seam to the phasea service | Yes — `scoped()` to `Services\RemoteAiClient` unconditionally. No override point exists; there is no other driver. |
| `NotifiesOperator` | short status messages the AI jobs send to the user who started the work | Yes — `bindIf()` to `Support\Notifications\LoggingOperatorNotifier` (logs only). `core45/ai-core-filament` binds a Filament-notification implementation over it if installed; an app that binds its own first keeps it. |
| `IssuesGuestStreamTokens` | short-lived tokens for anonymous chat | **No.** Host must bind. |
| `EnrichesCitations` | GEO citation enrichment during content rewrite | **No.** Host must bind. |
| `GeneratesSlugs` | URL slug generation for translated content | **No.** Host must bind. |
| `ChecksContentSimilarity` | duplicate-content guard used by the rewrite pipeline | **No.** Host must bind. |
| `StoresFocusKeyphrases` | persists SEO focus keyphrases | **No.** Host must bind. |
| `GeneratesContentMedia` | image/media generation for a rewritten record | **No.** Host must bind. |
| `GeneratesImages` | raw image generation; two implementations ship (`Services\ImageGeneration\FalAiService`, `GeminiImageService`) but neither is bound automatically | **No.** Only resolved today by `core45/ai-posts-filament`'s rewrite action — a host without that package never touches it. |
| `ProvidesMandatoryWords` | store-wide mandatory-word list injected into prompt parameters | **No.** Defined here, but only ever `app()`-resolved from `core45/ai-posts` (`BlogPostWriterService`, `BlogFaqWriterService`, `DynamicContentModule`) — required if you install that package. |
| `ObservesAiContracts` | records what the host observed about a phasea prompt-contract capability, for exit-window observability only | **No.** Same as above — only called from `core45/ai-posts`. |
| `AIContentable` | model-implemented (not container-bound): declares AI capabilities/content fields on a host Eloquent model | N/A — implement it on the model itself, not bound in the container. |
| `AIQueryable` | model-implemented: declares AI-queryable fields/scopes/actions on a host Eloquent model | N/A — same as above. |
| `ProvidesSeoIndexable` | model-implemented: `getOrCreateSeoIndexable()` on a content model | N/A — same as above. |

`ModelRegistry` is not a contract but is also container-resolved: it is a
`scoped()` singleton built in `register()` straight from `config('ai.models')`,
`config('ai.url_slugs')` and `config('ai.seo_content_types')`. Re-binding it
yourself is unnecessary — editing the config is enough.

### Config keys the host is expected to set

- **`ai.models`** — the `ModelRegistry` map (`key => model FQCN`). The
  package ships **no default** for this key at all (it is absent from
  `config/ai.php` entirely) — every app registers its own. Any package code
  that calls `ModelRegistry::getModelClassOrFail($key)` for a key you have not
  registered throws an `InvalidArgumentException` naming the missing key and
  listing what *is* registered.
- **`ai.base_url`** / **`ai.token`** (`AI_SERVICE_BASE_URL`, `AI_SERVICE_TOKEN`)
  — no sensible default; `RemoteAiClient` needs both to reach the phasea
  service.
- **`ai.topic_patterns`** — the regex taxonomy `PreferenceInferenceService`
  and `UsageAnalyticsService` use to classify chat messages into topics for
  preference inference and usage analytics. Because `ai-core` is
  domain-neutral it cannot supply a real default, so the shipped value is an
  **example e-commerce taxonomy** (`orders`, `products`, `account`,
  `payments`, `support`, `general` — see `config/ai.php`), kept non-empty only
  so behaviour does not silently change for an app that already relied on it.
  An app in another domain should replace it with its own taxonomy, or set it
  to `[]` to disable topic classification outright.
- **`ai.finalize_job`** / **`ai.analyze_feedback_job`** — host job class
  names (`Models\AIContentBatch::checkCompletion()` and
  `Observers\AIConversationMessageObserver::updated()`). No default; left
  unset, the package explicitly skips that dispatch rather than guessing a
  class.
- `ai.filament.navigation_group` (defaults to `'AI'`) and
  `ai.factories.batchable_type` (test fixtures only) — lower-stakes, listed
  for completeness rather than because an install fails without them.

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
