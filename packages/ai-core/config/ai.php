<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ai-core — Package Config Defaults (D6)
|--------------------------------------------------------------------------
| Core transport concerns only: the phasea connection, the driver seam and
| the stream tuning. The consuming app's config/ai.php overrides these
| values key-by-key; env variable names are stable across the extraction,
| so deployments carry no config churn. Domain concerns (surfaces, budgets,
| detection/humanization, assistant behaviour) stay in the host's own file.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Global AI Driver
    |--------------------------------------------------------------------------
    | "remote" (phasea) is the only supported driver (D17): the in-process
    | "local" AI stack was decommissioned and `AiClientFactory` resolves
    | `RemoteAiClient` unconditionally for every surface. The key is retained
    | for the revert runbook (docs/ai-engine-revert-runbook.md) only.
    */
    'driver' => env('AI_DRIVER', 'remote'),

    /*
    |--------------------------------------------------------------------------
    | Remote Service Connection
    |--------------------------------------------------------------------------
    | Base URL and Sanctum bearer token for the phasea multi-tenant AI service.
    */
    'base_url' => env('AI_SERVICE_BASE_URL'),
    'token' => env('AI_SERVICE_TOKEN'),
    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),
    'timeout_max' => (int) env('AI_SERVICE_TIMEOUT_MAX', 300),

    /*
    |------------------------------------------------------------------
    | Consumer-supplied job classes
    |------------------------------------------------------------------
    | Both jobs edit host resources and notify host users, so they stay
    | consumer-owned and the package only resolves them by class string.
    | The package ships NO default: naming a host class here would make
    | ai-core unloadable in any app that does not define it. Set them in
    | your own `config/ai.php` (this app does) or via the env keys below;
    | left null, the package simply skips the dispatch.
    */
    'finalize_job' => env('AI_FINALIZE_BATCH_JOB'),

    // Consumer-owned feedback analysis loop (admin assistant stack, A3/A13).
    'analyze_feedback_job' => env('AI_ANALYZE_FEEDBACK_JOB'),

    /*
    |------------------------------------------------------------------
    | AI Assistant
    |------------------------------------------------------------------
    | Master switch for the assistant surface, including the four Filament
    | resources this package ships. Every one of them gates `canAccess()` on
    | this key, so with it off the resources exist, route, and 403 — they are
    | not merely hidden from navigation.
    |
    | Declared HERE, not only in the consuming app, because the package's own
    | code reads it. It used to be read but never declared, which meant a fresh
    | install got four resources that silently never appeared and no key in the
    | published config to discover why. Same class of silent install step that
    | §9 step 7 removed for the plugin registration.
    |
    | Default false: this pulls in the AI service, so a consumer opts in.
    */
    'assistant' => [
        'enabled' => env('AI_ASSISTANT_ENABLED', false),
    ],

    /*
    |------------------------------------------------------------------
    | Filament Integration
    |------------------------------------------------------------------
    | Admin navigation group for the resources this package ships. A plain
    | string by default so the package imports no host enum; consuming apps
    | may set an enum case implementing Filament's HasLabel instead.
    */
    'filament' => [
        'navigation_group' => 'AI',

        /*
        | Which panels AiCoreServiceProvider registers AiCorePlugin on.
        |
        |   null  — every panel (the right default for a single-panel app)
        |   ['admin'] — only the named panels
        |   []    — none; register the plugin by hand in your panel provider
        |
        | An empty array means NONE, not "unset". Opting out explicitly must not
        | silently hand you the UI anyway.
        */
        'panels' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream Tuning
    |--------------------------------------------------------------------------
    | SSE proxy timeouts/heartbeats shared by every chat surface.
    */
    'stream' => [
        'connect_timeout' => (int) env('AI_STREAM_CONNECT_TIMEOUT', 5),
        'idle_timeout' => (int) env('AI_STREAM_IDLE_TIMEOUT', 120),
        'total_timeout' => (int) env('AI_STREAM_TOTAL_TIMEOUT', 600),
        'heartbeat_interval' => (int) env('AI_STREAM_HEARTBEAT_INTERVAL', 15),
    ],

];
