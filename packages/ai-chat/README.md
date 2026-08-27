# core45/ai-chat

Customer-facing AI chat transport: the `CustomerAIService`, guest stream token
brokering, the Livewire chat panel and its view, the SSE proxy controller, and
the conversation summarizer command.

Scope is deliberately **customer transport only**. The admin assistant stack
(`AIAssistantService`, the admin `AIChatPanel`, the assistant API controller and
the feedback-analysis loop) stays in the host application until it gets
contracts of its own.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

## Requirements

PHP 8.4+, Laravel 13, `core45/ai-core: ^0.1`. Declared dependencies:
`livewire/livewire`, `stevebauman/purify`. No Filament dependency, direct or
transitive — `composer.json` names none, `AiChatServiceProvider` imports none,
and nothing under `src/` references a Filament class. Of the three packages
this README family covers, `ai-chat` is the only one that never required a
Filament panel: it installs into a Livewire-only app with no admin UI at all.

## Installation

Auto-discovered via `extra.laravel.providers`
(`Core45\AiChat\AiChatServiceProvider`). Then bind the two seams below and add
the routes.

## What this package provides

- `Services\CustomerAIService` — the customer chat flow.
- `Services\GuestStreamTokenIssuer` — short-lived tokens for anonymous chat.
- `Livewire\CustomerAIChatPanel`, registered under the pre-extraction tag
  `front.customer-a-i-chat-panel`, so existing
  `<livewire:front.customer-a-i-chat-panel />` markup keeps working.
- Views under the `ai-chat::` namespace.
- `Http\Controllers\ChatStreamProxyController` — SSE proxy with `adminStream`,
  `customerStream` and `guestStream` actions.
- `Console\Commands\SummarizeCustomerConversations`, registered automatically.

**This package owns no database tables.** Conversations, messages, summaries and
message feedback all live in `core45/ai-core`, which owns the whole `ai_*`
schema.

## How a host application integrates this

`ai-chat` contains **no** `App\` imports and boots standalone. Two contracts are
seams the package defines and the consumer implements — the full set defined
under `Core45\AiChat\Contracts\`. Neither is bound by
`AiChatServiceProvider` (it binds nothing at all — no `bind`/`bindIf`/
`singleton`/`scoped` call exists in it); the package deliberately ships no
default binding, since binding a host class from inside the package is what
would make it unloadable elsewhere.

There is no `config/ai-chat.php` — this package has no config file of its
own and reads none; every setting it needs (stream tuning, base URL/token)
comes from `core45/ai-core`'s `config('ai.*')`.

| Contract | Supplies |
|---|---|
| `Contracts\CustomerContextContract` (D9) | the signed-in customer's order history, voucher state and pricing context, as plain arrays |
| `Contracts\ChatProductCardContract` (D8) | validation and rendering of product recommendations in assistant replies |

```php
// app/Providers/AiServiceProvider.php
$this->app->scoped(CustomerContextContract::class, fn ($app) => $app->make(CustomerContextService::class));
$this->app->scoped(ChatProductCardContract::class, fn ($app) => $app->make(HostChatProductCardValidator::class));
```

Resolving `CustomerAIService` without those bindings throws a
`BindingResolutionException` naming the contract — that is intentional.

### Routes

The package does **not** register routes; the host owns them so it can pick its
own middleware and URL prefixes. In phaseb (`routes/web.php`):

```php
Route::get('/ai/chat/stream',          [ChatStreamProxyController::class, 'adminStream']);
Route::get('/ai/customer-chat/stream', [ChatStreamProxyController::class, 'customerStream']);
Route::get('/ai/guest-chat/stream',    [ChatStreamProxyController::class, 'guestStream']);
```

## Testing

```bash
vendor/bin/phpunit -c packages/ai-chat/phpunit.xml.dist
```

Orchestra Testbench with only Livewire + ai-core + ai-chat booted.
`tests/PackageWiringTest.php` asserts the provider boots, the Livewire tag and
view namespace resolve, that no file under `src/` imports `App\`, and that the
two contracts are left unbound for the consumer.
