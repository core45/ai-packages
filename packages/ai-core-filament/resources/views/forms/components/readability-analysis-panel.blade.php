@php
    $score = $getScore();
    $issues = $getReadabilityIssues();
    $groupedIssues = $getGroupedReadabilityIssues();
    $passedCount = $getPassedCount();
    $failedCount = $getFailedCount();
    $analyzedAt = $getAnalyzedAt();
    $overallStatus = $getOverallStatus();

    $color = match(true) {
        $score === null => '#9ca3af',
        $score >= 70 => '#22c55e',
        $score >= 40 => '#2196F3',
        default => '#ef4444',
    };

    $statusBadge = match($overallStatus) {
        'good' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'ok' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        'needs_improvement' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        'poor' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        default => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
    };

    $statusLabel = match($overallStatus) {
        'good' => __('Good'),
        'ok' => __('OK'),
        'needs_improvement' => __('Needs Improvement'),
        'poor' => __('Poor'),
        default => __('Not Analyzed'),
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
@endphp

<div
    x-data="{ open: true }"
    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
>
    {{-- Header --}}
    <button
        type="button"
        class="flex w-full items-center gap-4 px-4 py-3"
        x-on:click="open = ! open"
    >
        {{-- Score circle --}}
        <div class="shrink-0">
            <svg viewBox="0 0 36 36" style="width: 48px; height: 48px;">
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
                    stroke="{{ $color }}"
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
                    fill="{{ $color }}"
                    style="transition: fill 0.4s ease;"
                >{{ $score !== null ? $score : '—' }}</text>
            </svg>
        </div>

        {{-- Title and status --}}
        <div class="flex flex-1 items-center gap-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('Readability Analysis') }}
            </h3>

            @if ($overallStatus)
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge }}">
                    {{ $statusLabel }}
                </span>
            @endif
        </div>

        {{-- Collapse indicator --}}
        <x-heroicon-o-chevron-down
            class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200 dark:text-gray-500"
            x-bind:class="open ? 'rotate-180' : ''"
        />
    </button>

    {{-- Body --}}
    <div x-show="open" x-collapse>
        <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700">
            @if (empty($issues))
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
                                            'uniqueId' => 'readability-' . $severity . '-' . $index,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach

                    {{-- Good results (collapsible) --}}
                    @if (! empty($groupedIssues['good']))
                        <div x-data="{ goodOpen: false }">
                            <button
                                type="button"
                                class="mb-2 flex w-full items-center gap-1 text-xs font-semibold uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                x-on:click="goodOpen = ! goodOpen"
                            >
                                <x-heroicon-o-chevron-right
                                    class="h-3 w-3 transition-transform duration-200"
                                    x-bind:class="goodOpen ? 'rotate-90' : ''"
                                />
                                {{ $severityGroups['good']['label'] }}
                                <span class="ml-1 text-gray-400 dark:text-gray-500">({{ count($groupedIssues['good']) }})</span>
                            </button>
                            <div x-show="goodOpen" x-collapse>
                                <div class="space-y-1">
                                    @foreach ($groupedIssues['good'] as $index => $issue)
                                        @include('ai-core::forms.components.partials.analysis-issue-row', [
                                            'issue' => $issue,
                                            'config' => $severityGroups['good'],
                                            'uniqueId' => 'readability-good-' . $index,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Footer --}}
        @if ($analyzedAt)
            <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-700">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Last analyzed') }}: {{ $analyzedAt instanceof \Illuminate\Support\Carbon ? $analyzedAt->diffForHumans() : $analyzedAt }}
                </p>
            </div>
        @else
            <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-700">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Not yet analyzed') }}
                </p>
            </div>
        @endif
    </div>
</div>
