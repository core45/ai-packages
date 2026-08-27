{{--
    NOTE: the source view offered an "Improve SEO" button that called
    `improveSeoForLocale()` on the page's Livewire component (defined in the
    `HandlesAiSeoImprovement` concern, backed by a host SEO improvement
    service). Neither the improvement service nor its dispatch job exist in
    this application, so that action has been dropped. Everything else
    (score circle, issue groups, external insights) is unchanged.
--}}
@php
    $score = $getScore();
    $scoreColor = $getScoreColor();
    $overallStatus = $getOverallStatus();
    $analyzedAt = $getAnalyzedAt();
    $seoIssues = $getSeoIssues();
    $groupedIssues = $getGroupedSeoIssues();
    $externalIssues = $getExternalIssues();
    $groupedExternalIssues = $getGroupedExternalIssues();
    $hasExternalIssues = ! empty($externalIssues);

    $passedCount = count(array_filter($seoIssues, fn ($issue) => ($issue['severity'] ?? '') === 'good'));
    $failedCount = count(array_filter($seoIssues, fn ($issue) => ($issue['severity'] ?? '') !== 'good'));

    $statusBadge = match ($overallStatus) {
        'good' => ['label' => __('Good'), 'class' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'],
        'ok' => ['label' => __('OK'), 'class' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400'],
        'needs_improvement' => ['label' => __('Needs Improvement'), 'class' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400'],
        'poor' => ['label' => __('Poor'), 'class' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'],
        default => ['label' => __('Not Analyzed'), 'class' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'],
    };

    $severityGroups = [
        'error' => [
            'label' => __('Problems'),
            'icon' => 'heroicon-o-x-circle',
            'iconColor' => 'text-red-500',
            'badgeBg' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        ],
        'warning' => [
            'label' => __('Improvements'),
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'text-orange-500',
            'badgeBg' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        ],
        'ok' => [
            'label' => __('Considerations'),
            'icon' => 'heroicon-o-information-circle',
            'iconColor' => 'text-blue-500',
            'badgeBg' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        ],
        'good' => [
            'label' => __('Good results'),
            'icon' => 'heroicon-o-check-circle',
            'iconColor' => 'text-green-500',
            'badgeBg' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        ],
    ];

    $dashOffset = $score !== null ? (100 - $score) : 100;
@endphp

<div
    x-data="{ panelOpen: true }"
    class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
>
    {{-- Header --}}
    <button
        type="button"
        class="flex w-full items-center gap-4 px-4 py-3"
        x-on:click="panelOpen = ! panelOpen"
    >
        {{-- Score circle (inline SVG) --}}
        <div class="shrink-0" style="width: 48px; height: 48px;">
            <svg viewBox="0 0 36 36" style="width: 100%; height: 100%;">
                {{-- Background circle --}}
                <path
                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none"
                    stroke="#e5e7eb"
                    stroke-width="3"
                />
                {{-- Progress circle --}}
                <path
                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none"
                    stroke="{{ $scoreColor }}"
                    stroke-width="3"
                    stroke-dasharray="{{ $score ?? 0 }}, 100"
                    stroke-linecap="round"
                    style="transition: stroke-dasharray 0.4s ease, stroke 0.4s ease;"
                />
                {{-- Score text --}}
                <text
                    x="18"
                    y="20.35"
                    text-anchor="middle"
                    font-size="8"
                    font-weight="bold"
                    fill="{{ $scoreColor }}"
                    style="transition: fill 0.4s ease;"
                >{{ $score !== null ? $score : '—' }}</text>
            </svg>
        </div>

        {{-- Title and status --}}
        <div class="min-w-0 flex-1 text-left">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('SEO Analysis') }}
            </h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('SEO') }}</span>
        </div>

        {{-- Status badge --}}
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusBadge['class'] }}">
            {{ $statusBadge['label'] }}
        </span>

        {{-- Chevron --}}
        <x-heroicon-o-chevron-down
            class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200 dark:text-gray-500"
            x-bind:class="panelOpen ? 'rotate-180' : ''"
        />
    </button>

    {{-- Body --}}
    <div x-show="panelOpen" x-collapse>
        <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700">
            @if (empty($seoIssues))
                <p class="text-sm italic text-gray-400 dark:text-gray-500">
                    {{ __('No analysis results yet. Run analysis to see results.') }}
                </p>
            @else
                {{-- Summary line --}}
                <div class="mb-4 flex items-center gap-2 text-sm">
                    <span class="inline-flex items-center gap-1 font-medium text-green-600 dark:text-green-400">
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        {{ $passedCount }} {{ __('passed') }}
                    </span>
                    <span class="text-gray-400 dark:text-gray-500">&middot;</span>
                    <span class="inline-flex items-center gap-1 font-medium text-orange-600 dark:text-orange-400">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                        {{ $failedCount }} {{ __('need improvement') }}
                    </span>
                </div>

                {{-- Issue groups --}}
                <div class="space-y-4">
                    @foreach (['error', 'warning', 'ok'] as $severity)
                        @if (! empty($groupedIssues[$severity]))
                            <div>
                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    {{ $severityGroups[$severity]['label'] }}
                                </h4>
                                <div class="space-y-1">
                                    @foreach ($groupedIssues[$severity] as $index => $issue)
                                        @include('ai-core::forms.components.partials.analysis-issue-row', [
                                            'issue' => $issue,
                                            'config' => $severityGroups[$severity],
                                            'uniqueId' => 'seo-panel-' . $severity . '-' . $index,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach

                    {{-- Good results (collapsible) --}}
                    @if (! empty($groupedIssues['good']))
                        <div x-data="{ open: true }">
                            <button
                                type="button"
                                class="mb-2 flex w-full items-center gap-1 text-xs font-semibold uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                x-on:click="open = ! open"
                            >
                                <x-heroicon-o-chevron-right
                                    class="h-3 w-3 transition-transform duration-200"
                                    x-bind:class="open ? 'rotate-90' : ''"
                                />
                                {{ $severityGroups['good']['label'] }}
                                <span class="ml-1 text-gray-400 dark:text-gray-500">({{ count($groupedIssues['good']) }})</span>
                            </button>
                            <div x-show="open" x-collapse>
                                <div class="space-y-1">
                                    @foreach ($groupedIssues['good'] as $index => $issue)
                                        @include('ai-core::forms.components.partials.analysis-issue-row', [
                                            'issue' => $issue,
                                            'config' => $severityGroups['good'],
                                            'uniqueId' => 'seo-panel-good-' . $index,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- External Insights --}}
        @if (! empty($seoIssues))
            <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700" x-data="{ externalOpen: true }">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    x-on:click="externalOpen = ! externalOpen"
                >
                    <x-heroicon-o-globe-alt class="h-4 w-4" />
                    <x-heroicon-o-chevron-right
                        class="h-3 w-3 transition-transform duration-200"
                        x-bind:class="externalOpen ? 'rotate-90' : ''"
                    />
                    {{ __('External Insights') }}
                    @if ($hasExternalIssues)
                        <span class="ml-1 text-gray-400 dark:text-gray-500">({{ count($externalIssues) }})</span>
                    @endif
                </button>

                <div x-show="externalOpen" x-collapse class="mt-3">
                    @if ($hasExternalIssues)
                        <div class="space-y-4">
                            @foreach (['error', 'warning', 'ok', 'good'] as $severity)
                                @if (! empty($groupedExternalIssues[$severity]))
                                    <div>
                                        <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            {{ $severityGroups[$severity]['label'] }}
                                        </h4>
                                        <div class="space-y-1">
                                            @foreach ($groupedExternalIssues[$severity] as $index => $issue)
                                                @include('ai-core::forms.components.partials.analysis-issue-row', [
                                                    'issue' => $issue,
                                                    'config' => $severityGroups[$severity],
                                                    'uniqueId' => 'external-' . $severity . '-' . $index,
                                                ])
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs italic text-gray-400 dark:text-gray-500">
                            {{ __('Powered by external SEO insight providers') }}
                        </p>
                    @else
                        <p class="text-xs italic text-gray-400 dark:text-gray-500">
                            {{ __('No external services configured') }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Footer --}}
        <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-700">
            <p class="text-xs text-gray-400 dark:text-gray-500">
                @if ($analyzedAt)
                    {{ __('Last analyzed') }}: {{ $analyzedAt instanceof \Carbon\Carbon ? $analyzedAt->diffForHumans() : $analyzedAt }}
                @else
                    {{ __('Not analyzed yet') }}
                @endif
            </p>
        </div>
    </div>
</div>
