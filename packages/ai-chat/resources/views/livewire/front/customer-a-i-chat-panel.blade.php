<div
    x-data="{
        open: @entangle('isOpen'),
        streamingContent: '',
        currentTool: null,
        toolSteps: [],
        conversationId: @entangle('conversationId'),
        streamingMessageId: @entangle('streamingMessageId'),

        // Streaming transport: 'remote' (host-proxied SSE via EventSource) or 'local'
        // (queued-job, no live stream — content arrives via the sync fallback; the
        // Reverb broadcast path was removed in CUT-07).
        {{-- @js (not @json): string output must not carry double quotes inside the x-data attribute --}}
        chatDriver: @js($this->getCustomerChatDriver()),
        sseUrl: @js($this->getIsAuthenticatedProperty() ? route('ai.customer-chat.stream') : route('ai.guest-chat.stream')),
        eventSource: null,

        // Streaming configuration
        streamingEnabled: @json($this->getStreamingEnabled()),
        fallbackTimeoutMs: @json($this->getFallbackTimeout()),

        // Fallback state
        fallbackTimeout: null,
        usingSyncFallback: false,
        fallbackNotice: '',

        scrollToBottom() {
            this.$nextTick(() => {
                const container = this.$refs.messagesContainer;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },
        uuid() {
            if (crypto && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        // Message-id handshake (30-B1): open the SSE stream by reference to the
        // persisted user message — the proxy loads the stored body server-side.
        // (The old code read `this.pendingMessage`, which was never defined in
        // this component, so every stream request carried message='' and 422ed.)
        openSseStream(conversationId, userMessageId) {
            if (!conversationId || !userMessageId) return;
            if (!this.streamingEnabled) return;

            this.cancelFallbackTimer();
            this.usingSyncFallback = false;
            this.fallbackNotice = '';

            const operationId = this.uuid();
            const params = new URLSearchParams({
                conversation_id: String(conversationId),
                message_id: String(userMessageId),
                operation_id: operationId,
            });

            const url = this.sseUrl + '?' + params.toString();

            if (this.eventSource) {
                this.eventSource.close();
            }

            this.eventSource = new EventSource(url);
            this.eventSource.onmessage = (ev) => {
                try {
                    this.handleStreamEvent(JSON.parse(ev.data));
                } catch (err) {
                    console.warn('[Customer AI Chat] Failed to parse SSE event', err);
                }
            };
            this.eventSource.onerror = () => {
                // Transport-level failure (proxy 4xx, dropped connection): the
                // 'done'/'error' handlers already closed the stream on the happy
                // paths, so reaching here means the stream died without a
                // terminal event. ALWAYS reset streaming state, then fall back
                // ONCE to the direct (sync) path so the widget never dies silently.
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }

                const placeholderId = this.streamingMessageId;

                if (placeholderId && !this.usingSyncFallback) {
                    this.cancelFallbackTimer();
                    this.usingSyncFallback = true;
                    this.fallbackNotice = '{{ __("Connection slower than expected, switching to direct mode...") }}';
                    $wire.sendMessageSync(placeholderId)
                        .then(() => {
                            this.clearFallbackState();
                            this.clearPending();
                        })
                        .catch(() => {
                            this.clearFallbackState();
                            this.clearPending();
                            $wire.resetStreamingState();
                        });
                    return;
                }

                this.clearFallbackState();
                this.clearPending();
                $wire.resetStreamingState();
            };
        },

        handleStreamEvent(event) {
            const type = event.type;
            const payload = event.payload || {};

            switch (type) {
                case 'chunk':
                    this.cancelFallbackTimer();
                    this.streamingContent += payload.text || '';
                    this.scrollToBottom();
                    break;
                case 'tool_start':
                    this.cancelFallbackTimer();
                    this.currentTool = payload.name || 'tool';
                    this.toolSteps.push({ tool: payload.name, name: payload.name, status: 'running' });
                    this.scrollToBottom();
                    break;
                case 'tool_complete':
                    this.cancelFallbackTimer();
                    const step = this.toolSteps.find(s => s.tool === payload.name && s.status === 'running');
                    if (step) step.status = 'complete';
                    this.currentTool = null;
                    break;
                case 'error': {
                    // Render cs12's localized bubble instead of console-logging
                    // (30-B1): rate-limit (HTTP 429) and budget (HTTP 402)
                    // failures get a friendly, translated message.
                    const errorText = payload.message || '';
                    const errorCode = payload.code
                        || (errorText.includes('HTTP 429') ? 'rate_limited' : null)
                        || (errorText.includes('HTTP 402') ? 'budget_exceeded' : null);
                    this.clearFallbackState();
                    this.clearPending();
                    $wire.handleStreamError(errorCode);
                    if (this.eventSource) { this.eventSource.close(); this.eventSource = null; }
                    break;
                }
                case 'done':
                    console.log('[Customer AI Chat] SSE stream complete');
                    this.clearFallbackState();
                    this.clearPending();
                    $wire.resetStreamingState();
                    $wire.$refresh();
                    if (this.eventSource) { this.eventSource.close(); this.eventSource = null; }
                    break;
                case 'heartbeat':
                default:
                    break;
            }
        },
        clearPending() {
            this.streamingContent = '';
            this.toolSteps = [];
            this.currentTool = null;
        },

        // Start fallback timer after sending message (only if streaming enabled)
        startFallbackTimer(messageId) {
            if (!this.streamingEnabled) return;

            this.cancelFallbackTimer();
            this.usingSyncFallback = false;
            this.fallbackNotice = '';

            this.fallbackTimeout = setTimeout(() => {
                if (this.streamingMessageId && !this.usingSyncFallback) {
                    console.log('[Customer AI Chat] Fallback timeout triggered, switching to sync mode');
                    this.usingSyncFallback = true;
                    this.fallbackNotice = '{{ __("Taking longer than expected...") }}';
                    $wire.sendMessageSync(messageId);
                }
            }, this.fallbackTimeoutMs);
        },

        // Cancel fallback timer (called when WebSocket event arrives)
        cancelFallbackTimer() {
            if (this.fallbackTimeout) {
                clearTimeout(this.fallbackTimeout);
                this.fallbackTimeout = null;
            }
        },

        // Clear fallback state
        clearFallbackState() {
            this.cancelFallbackTimer();
            this.usingSyncFallback = false;
            this.fallbackNotice = '';
        }
    }"
    x-init="
        $watch('open', value => { if (value) scrollToBottom() });
    "
    x-on:livewire:navigating.window="if (eventSource) eventSource.close()"
    {{-- Message-id handshake (30-B1): sendMessage() dispatches the persisted ids
         and the stream is opened by reference — no client-side text echo. --}}
    x-on:customer-chat-message-created.window="
        if (streamingEnabled) {
            if (chatDriver === 'remote') {
                openSseStream($event.detail.conversationId, $event.detail.userMessageId);
            } else {
                startFallbackTimer($event.detail.assistantMessageId);
            }
        }
    "
    @chat-updated.window="scrollToBottom()"
    class="fixed z-50"
    :class="open ? 'bottom-0 right-0 sm:bottom-4 sm:right-6' : 'bottom-4 right-6'"
    role="complementary"
    aria-label="{{ __('customer_ai.aria_label') }}"
>
    {{-- Floating Button (when closed) --}}
    <button
        x-show="!open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        wire:click="toggle"
        class="flex items-center justify-center w-14 h-14 rounded-full bg-blue-500 text-white shadow-lg hover:bg-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all"
        aria-label="{{ __('customer_ai.open_chat') }}"
    >
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
    </button>

    {{-- Chat Panel (when open) --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        class="w-full sm:w-96 h-[100dvh] sm:h-[650px] bg-white dark:bg-gray-800 sm:rounded-lg shadow-2xl flex flex-col overflow-hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="chat-title"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 bg-slate-900 text-white">
            <h2 id="chat-title" class="font-semibold">{{ __('customer_ai.title') }}</h2>
            <div class="flex items-center gap-2">
                <button
                    wire:click="clearChat"
                    class="p-1 hover:bg-slate-700 rounded transition-colors"
                    aria-label="{{ __('customer_ai.clear_chat') }}"
                    title="{{ __('customer_ai.clear_chat') }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
                <button
                    wire:click="toggle"
                    class="p-1 hover:bg-slate-700 rounded transition-colors"
                    aria-label="{{ __('customer_ai.close_chat') }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- Messages Container --}}
        <div
            x-ref="messagesContainer"
            class="flex-1 overflow-y-auto p-4 space-y-4"
        >
            @foreach($messages as $msg)
                @if(!is_array($msg))
                    @continue
                @endif
                <div wire:key="message-{{ $loop->index }}" class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] rounded-lg px-4 py-2 {{ $msg['role'] === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' }}">
                        {{-- Text content (x-text for XSS safety, white-space: pre-wrap for line breaks) --}}
                        @if(!empty($msg['content']))
                            <div class="prose prose-sm dark:prose-invert max-w-none" style="white-space: pre-wrap;" x-data="{ content: @js($msg['content']) }" x-text="content"></div>
                        @endif

                        {{-- Feedback buttons for AI messages --}}
                        @if($msg['role'] === 'assistant' && !empty($msg['content']))
                            @php
                                $feedback = $msg['feedback'] ?? null;
                                $hasRated = $feedback !== null;
                            @endphp
                            <div class="flex items-center gap-1 mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                                <span class="text-xs text-gray-400 dark:text-gray-500 mr-1">{{ __('customer_ai.was_helpful') }}</span>
                                <button
                                    wire:click="rateFeedback({{ $loop->index }}, 'positive')"
                                    class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors {{ $feedback === 'positive' ? 'text-green-500' : 'text-gray-400 hover:text-green-500' }}"
                                    title="{{ __('customer_ai.helpful') }}"
                                    @disabled($hasRated)
                                >
                                    <svg class="w-4 h-4" fill="{{ $feedback === 'positive' ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
                                    </svg>
                                </button>
                                <button
                                    wire:click="rateFeedback({{ $loop->index }}, 'negative')"
                                    class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors {{ $feedback === 'negative' ? 'text-red-500' : 'text-gray-400 hover:text-red-500' }}"
                                    title="{{ __('customer_ai.not_helpful') }}"
                                    @disabled($hasRated)
                                >
                                    <svg class="w-4 h-4" fill="{{ $feedback === 'negative' ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" />
                                    </svg>
                                </button>
                            </div>
                        @endif

                        {{-- Product recommendations --}}
                        @if(isset($msg['type']) && $msg['type'] === 'product_recommendations' && !empty($msg['products']))
                            <div class="mt-3 space-y-3">
                                @foreach($msg['products'] as $product)
                                    <div wire:key="product-{{ $loop->parent->index }}-{{ $product['id'] }}" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 p-3 shadow-sm">
                                        <div class="flex gap-3">
                                            {{-- Product image --}}
                                            <div class="shrink-0">
                                                @if(!empty($product['image_url']))
                                                    <img
                                                        src="{{ $product['image_url'] }}"
                                                        alt="{{ $product['name'] ?? '' }}"
                                                        class="w-20 h-20 object-cover rounded-md"
                                                    />
                                                @else
                                                    <div class="w-20 h-20 bg-gray-200 dark:bg-gray-600 rounded-md flex items-center justify-center">
                                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                        </svg>
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- Product info --}}
                                            <div class="flex-1 min-w-0">
                                                {{-- Name with badges --}}
                                                <div class="flex items-start gap-2 flex-wrap">
                                                    <h4 class="font-medium text-gray-900 dark:text-white text-sm leading-tight">
                                                        {{ $product['name'] ?? __('Unknown product') }}
                                                    </h4>
                                                    @if(!empty($product['priority_boost']))
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                            {{ __('Recommended') }}
                                                        </span>
                                                    @endif
                                                    @if(!empty($product['is_best_product']))
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                            {{ __('Popular Choice') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                {{-- Summary --}}
                                                @if(!empty($product['summary']))
                                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 line-clamp-2">
                                                        {{ $product['summary'] }}
                                                    </p>
                                                @endif

                                                {{-- Price --}}
                                                <div class="mt-2 flex items-center gap-2">
                                                    @if(!empty($product['promo_price']))
                                                        <span class="text-sm font-semibold text-red-600 dark:text-red-400">
                                                            {{ number_format($product['promo_price'] / 100, 2, ',', ' ') }} EUR
                                                        </span>
                                                        <span class="text-xs text-gray-500 line-through">
                                                            {{ number_format($product['price'] / 100, 2, ',', ' ') }} EUR
                                                        </span>
                                                    @elseif(!empty($product['price']))
                                                        <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                                            {{ number_format($product['price'] / 100, 2, ',', ' ') }} EUR
                                                        </span>
                                                    @endif

                                                    @if(isset($product['in_stock']) && !$product['in_stock'])
                                                        <span class="text-xs text-red-500">{{ __('Out of stock') }}</span>
                                                    @endif
                                                </div>

                                                {{-- Action buttons --}}
                                                <div class="mt-2 flex gap-2">
                                                    @if(!empty($product['slug']))
                                                        <a
                                                            href="{{ route('front.product.buy-now', $product['slug']) }}"
                                                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded bg-blue-500 text-white hover:bg-blue-400 transition-colors"
                                                        >
                                                            {{ __('Buy Now') }}
                                                        </a>
                                                        <a
                                                            href="{{ route('front.product.show', ['productSlug' => $product['slug']]) }}"
                                                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                                        >
                                                            {{ __('See Details') }}
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Streaming indicator with live content --}}
            <template x-if="streamingMessageId">
                <div class="flex justify-start">
                    <div class="max-w-[85%] bg-gray-100 dark:bg-gray-700 rounded-lg px-4 py-2 text-gray-900 dark:text-gray-100">
                        {{-- Tool steps --}}
                        <template x-if="toolSteps.length > 0">
                            <div class="mb-2 space-y-1">
                                <template x-for="step in toolSteps" :key="step.tool">
                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <template x-if="step.status === 'running'">
                                            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </template>
                                        <template x-if="step.status === 'complete'">
                                            <svg class="w-3 h-3 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </template>
                                        <template x-if="step.status === 'failed'">
                                            <svg class="w-3 h-3 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </template>
                                        <span x-text="step.name"></span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Streaming content or waiting message --}}
                        <template x-if="streamingContent">
                            <div class="prose prose-sm dark:prose-invert max-w-none">
                                <span x-text="streamingContent"></span>
                                <span class="inline-block w-2 h-4 bg-gray-400 dark:bg-gray-500 ml-0.5" style="animation: blink 1s step-end infinite;"></span>
                            </div>
                        </template>
                        <template x-if="!streamingContent && toolSteps.length === 0 && !usingSyncFallback">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('customer_ai.thinking') }}</span>
                                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                            </div>
                        </template>

                        {{-- Sync fallback notice (30-B1): stream failed, retrying via direct mode --}}
                        <template x-if="!streamingContent && usingSyncFallback">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 animate-spin text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span class="text-sm text-gray-600 dark:text-gray-300" x-text="fallbackNotice || '{{ __('customer_ai.thinking') }}'"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Legacy loading indicator (for sendMessageSync fallback) --}}
            @if($isLoading)
                <div class="flex justify-start">
                    <div class="bg-gray-100 dark:bg-gray-700 rounded-lg px-4 py-2">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('customer_ai.thinking') }}</span>
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Error message --}}
            @if($error)
                <div class="bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-2 rounded-lg text-sm">
                    {{ $error }}
                </div>
            @endif
        </div>

        {{-- Contact Fallback --}}
        <div class="px-4 py-2 bg-gray-50 dark:bg-gray-700/50 border-t border-gray-200 dark:border-gray-600">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('customer_ai.need_more_help') }}
                <a href="{{ $this->contactUrl }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                    {{ __('customer_ai.contact_support') }}
                </a>
            </p>
        </div>

        {{-- Blink animation for streaming cursor --}}
        <style>
            @keyframes blink {
                0%, 50% { opacity: 1; }
                51%, 100% { opacity: 0; }
            }
        </style>

        {{-- Input Form --}}
        <form
            wire:submit="sendMessage"
            class="p-4 border-t border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800"
        >
            <div class="flex gap-2">
                <label for="chat-input" class="sr-only">{{ __('customer_ai.input_placeholder') }}</label>
                <input
                    id="chat-input"
                    type="text"
                    wire:model="message"
                    placeholder="{{ __('customer_ai.input_placeholder') }}"
                    class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    autocomplete="off"
                    :disabled="streamingMessageId !== null"
                    @disabled($isLoading)
                />
                <button
                    type="submit"
                    class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    :disabled="streamingMessageId !== null"
                    @disabled($isLoading)
                    aria-label="{{ __('customer_ai.send') }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 10.5L12 3m0 0l7.5 7.5M12 3v18" />
                    </svg>
                </button>
            </div>
        </form>
    </div>
</div>
