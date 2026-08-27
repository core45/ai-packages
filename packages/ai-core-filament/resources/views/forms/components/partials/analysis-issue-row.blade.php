@php
    $hasDetails = ! empty($issue['details']);
    $hasFix = ! empty($issue['fix']);
    $hasExpandable = $hasDetails || $hasFix;
@endphp

<div
    @if ($hasExpandable) x-data="{ expanded: true }" @endif
    class="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800"
>
    <div class="flex items-center gap-3">
        {{-- Severity icon --}}
        <div class="shrink-0">
            <x-dynamic-component
                :component="$config['icon']"
                class="h-5 w-5 {{ $config['iconColor'] }}"
            />
        </div>

        {{-- Message --}}
        <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ $issue['message'] ?? '' }}
            </p>
        </div>

        {{-- Score badge --}}
        @if (isset($issue['score']))
            <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $config['badgeBg'] }}">
                {{ $issue['score'] }}/9
            </span>
        @endif

        {{-- Expand button --}}
        @if ($hasExpandable)
            <button
                type="button"
                class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                x-on:click="expanded = ! expanded"
            >
                <x-heroicon-o-chevron-down
                    class="h-4 w-4 transition-transform duration-200"
                    x-bind:class="expanded ? 'rotate-180' : ''"
                />
            </button>
        @endif
    </div>

    {{-- Expandable details --}}
    @if ($hasExpandable)
        <div x-show="expanded" x-collapse class="mt-2 space-y-2 border-t border-gray-100 pt-2 dark:border-gray-700">
            @if ($hasDetails)
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $issue['details'] }}
                </div>
            @endif

            @if ($hasFix)
                <div class="flex items-start gap-2 rounded-md bg-blue-50 px-2.5 py-2 text-xs dark:bg-blue-900/20">
                    <x-heroicon-o-light-bulb class="mt-0.5 h-3.5 w-3.5 shrink-0 text-blue-500" />
                    <span class="text-blue-800 dark:text-blue-300">{{ $issue['fix'] }}</span>
                </div>
            @endif
        </div>
    @endif
</div>
