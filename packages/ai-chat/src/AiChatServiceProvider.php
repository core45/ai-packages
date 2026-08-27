<?php

declare(strict_types=1);

namespace Core45\AiChat;

use Core45\AiChat\Console\Commands\SummarizeCustomerConversations;
use Core45\AiChat\Livewire\CustomerAIChatPanel;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * ai-chat package provider (D11): registers customer chat transport —
 * services, Livewire component and views. The admin assistant stack stays in
 * the host (A3/A13).
 *
 * Contract bindings live in the CONSUMER, never here: `ChatProductCardContract`
 * (D8) and `CustomerContextContract` (D9) are seams this package defines and
 * the host implements. Binding a host class from inside the package would
 * defeat the inversion and make the package unloadable anywhere else — see
 * `App\Providers\AiServiceProvider` for this app's bindings.
 *
 * This package owns no tables: the conversation, summary and message-feedback
 * migrations ship with ai-core, which owns the whole `ai_*` schema.
 */
class AiChatServiceProvider extends ServiceProvider
{
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
