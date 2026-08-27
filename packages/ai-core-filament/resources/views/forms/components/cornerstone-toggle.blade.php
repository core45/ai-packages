@php
    $locale = $getLocale() ?? app()->getLocale();
@endphp

<div
    x-data="{
        enabled: @js($getRecord()?->isCornerstone($locale) ?? false),
        toggle() {
            this.enabled = !this.enabled;
            $wire.dispatch('toggle-cornerstone', { locale: @js($locale), enabled: this.enabled });
        }
    }"
    class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
>
    <button type="button" x-on:click="toggle()" class="shrink-0">
        <template x-if="enabled">
            <x-heroicon-s-star class="h-6 w-6 text-blue-400" />
        </template>
        <template x-if="!enabled">
            <x-heroicon-o-star class="h-6 w-6 text-gray-400 dark:text-gray-500" />
        </template>
    </button>
    <div>
        <p
            class="text-sm font-medium text-gray-700 dark:text-gray-300"
            x-bind:class="enabled ? 'text-blue-700 dark:text-blue-400' : ''"
        >
            {{ __('Cornerstone Content') }}
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Mark as cornerstone content. SEO analysis will apply stricter checks for cornerstone articles.') }}
        </p>
    </div>
</div>
