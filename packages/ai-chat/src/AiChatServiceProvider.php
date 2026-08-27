<?php

declare(strict_types=1);

namespace Core45\AiChat;

use Core45\AiChat\Console\Commands\SummarizeCustomerConversations;
use Core45\AiChat\Contracts\ChatProductCardContract;
use Core45\AiChat\Contracts\CustomerContextContract;
use Core45\AiChat\Livewire\CustomerAIChatPanel;
use Core45\AiChat\Support\NullChatProductCardValidator;
use Core45\AiChat\Support\NullCustomerContext;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * ai-chat package provider (D11): registers customer chat transport —
 * services, Livewire component and views. The admin assistant stack stays in
 * the host (A3/A13).
 *
 * The HOST'S OWN implementation still lives in the consumer, never here:
 * `ChatProductCardContract` (D8) and `CustomerContextContract` (D9) are seams
 * this package defines and the host implements against its real product/
 * customer models. Binding a host class from inside the package would defeat
 * the inversion and make the package unloadable anywhere else — see
 * `App\Providers\AiServiceProvider` for this app's bindings.
 *
 * What DOES belong here is a fallback for the host that has not bound
 * anything yet. Both contracts are resolved on the ordinary chat path —
 * `CustomerAIChatPanel::mount()` (Livewire's own lifecycle hook) resolves
 * `CustomerContextContract` via `CustomerAIService`'s constructor on every
 * render, and `validateProducts()` resolves `ChatProductCardContract`
 * whenever the assistant's reply contains a product recommendation — with no
 * config flag or `bound()` check guarding either resolution. A fresh
 * `composer require core45/ai-chat` into an application that has not yet
 * written its own `AiServiceProvider` binding threw
 * `BindingResolutionException` the moment the chat widget rendered, not
 * when a customer asked something that needed the host's data. `bindIf`
 * keeps the inversion intact: a host binding, whenever it is added, wins.
 *
 * This package owns no tables: the conversation, summary and message-feedback
 * migrations ship with ai-core, which owns the whole `ai_*` schema.
 */
class AiChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Neither default fabricates host data (no fake orders, no
        // rubber-stamped product cards) — see each Null* class's own
        // docblock. Both degrade the feature to "does nothing" rather than
        // trusting unvalidated input or breaking the install.
        $this->app->bindIf(ChatProductCardContract::class, NullChatProductCardValidator::class);
        $this->app->bindIf(CustomerContextContract::class, NullCustomerContext::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-chat');

        if ($this->app->runningInConsole()) {
            $this->commands([SummarizeCustomerConversations::class]);
        }

        // Keep the pre-extraction Livewire tag alias so existing blade
        // `<livewire:front.customer-a-i-chat-panel />` usage is untouched.
        Livewire::component('front.customer-a-i-chat-panel', CustomerAIChatPanel::class);
    }
}
