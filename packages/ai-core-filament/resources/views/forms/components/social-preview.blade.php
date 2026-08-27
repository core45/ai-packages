@php
    $ogTitlePath = $getOgTitleStatePath();
    $ogDescriptionPath = $getOgDescriptionStatePath();
    $ogImageUrl = $getOgImageUrl();
    $twitterTitlePath = $getTwitterTitleStatePath();
    $twitterDescriptionPath = $getTwitterDescriptionStatePath();
    $twitterImageUrl = $getTwitterImageUrl();
    $siteUrl = $getSiteUrl() ?? config('app.url');
    $fallbackTitlePath = $getFallbackTitleStatePath();
    $fallbackDescriptionPath = $getFallbackDescriptionStatePath();
@endphp

<div
    x-data="{
        activeTab: 'facebook',

        get ogTitle() {
            const ogVal = this.getStateValue('{{ $ogTitlePath }}');
            if (ogVal && ogVal.trim() !== '') return ogVal;
            return this.fallbackTitle;
        },

        get ogDescription() {
            const ogVal = this.getStateValue('{{ $ogDescriptionPath }}');
            if (ogVal && ogVal.trim() !== '') return ogVal;
            return this.fallbackDescription;
        },

        get twitterTitle() {
            const twVal = this.getStateValue('{{ $twitterTitlePath }}');
            if (twVal && twVal.trim() !== '') return twVal;
            return this.fallbackTitle;
        },

        get twitterDescription() {
            const twVal = this.getStateValue('{{ $twitterDescriptionPath }}');
            if (twVal && twVal.trim() !== '') return twVal;
            return this.fallbackDescription;
        },

        get fallbackTitle() {
            @if($fallbackTitlePath)
                const val = this.getStateValue('{{ $fallbackTitlePath }}');
                return val || '{{ __('Page Title') }}';
            @else
                return '{{ __('Page Title') }}';
            @endif
        },

        get fallbackDescription() {
            @if($fallbackDescriptionPath)
                const val = this.getStateValue('{{ $fallbackDescriptionPath }}');
                return val || '{{ __('Page description will appear here...') }}';
            @else
                return '{{ __('Page description will appear here...') }}';
            @endif
        },

        ogImageUrl: '{{ $ogImageUrl }}',
        twitterImageUrl: '{{ $twitterImageUrl }}',
        siteUrl: '{{ $siteUrl }}',

        get domain() {
            try {
                return new URL(this.siteUrl).hostname;
            } catch(e) {
                return this.siteUrl;
            }
        },

        truncate(text, maxLength) {
            if (!text) return '';
            return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
        },

        getStateValue(path) {
            if (!path) return '';
            const parts = path.split('.');
            let value = $wire.data;
            for (const part of parts) {
                if (value === undefined || value === null) return '';
                value = value[part];
            }
            return value || '';
        }
    }"
    class="space-y-3"
>
    {{-- Tab Navigation --}}
    <div class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
        <button
            type="button"
            @click="activeTab = 'facebook'"
            :class="activeTab === 'facebook'
                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white'
                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
            class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition-all"
        >
            Facebook
        </button>
        <button
            type="button"
            @click="activeTab = 'twitter'"
            :class="activeTab === 'twitter'
                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white'
                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
            class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition-all"
        >
            Twitter / X
        </button>
    </div>

    {{-- Facebook Preview --}}
    <div x-show="activeTab === 'facebook'" x-transition>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-600 dark:bg-gray-800">
            {{-- Image Area (1.91:1 aspect ratio = 1200x630) --}}
            <div class="relative bg-gray-100 dark:bg-gray-700" style="aspect-ratio: 1.91 / 1;">
                <template x-if="ogImageUrl">
                    <img
                        :src="ogImageUrl"
                        alt="OG Image Preview"
                        class="h-full w-full object-cover"
                    >
                </template>
                <template x-if="!ogImageUrl">
                    <div class="flex h-full w-full items-center justify-center">
                        <div class="text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                            </svg>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('No image set') }}</p>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Content Area --}}
            <div class="border-t border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-600 dark:bg-gray-750">
                <p
                    class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"
                    x-text="domain"
                ></p>
                <p
                    class="mt-1 line-clamp-2 text-sm font-bold leading-snug text-gray-900 dark:text-white"
                    x-text="truncate(ogTitle, 65)"
                ></p>
                <p
                    class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
                    x-text="truncate(ogDescription, 155)"
                ></p>
            </div>
        </div>
    </div>

    {{-- Twitter / X Preview --}}
    <div x-show="activeTab === 'twitter'" x-transition>
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-600 dark:bg-gray-800">
            {{-- Image Area (2:1 aspect ratio for summary_large_image) --}}
            <div class="relative bg-gray-100 dark:bg-gray-700" style="aspect-ratio: 2 / 1;">
                <template x-if="twitterImageUrl">
                    <img
                        :src="twitterImageUrl"
                        alt="Twitter Card Preview"
                        class="h-full w-full object-cover"
                    >
                </template>
                <template x-if="!twitterImageUrl">
                    <div class="flex h-full w-full items-center justify-center">
                        <div class="text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                            </svg>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('No image set') }}</p>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Content Area --}}
            <div class="border-t border-gray-200 px-3 py-2.5 dark:border-gray-600">
                <p
                    class="line-clamp-2 text-sm font-bold leading-snug text-gray-900 dark:text-white"
                    x-text="truncate(twitterTitle, 70)"
                ></p>
                <p
                    class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
                    x-text="truncate(twitterDescription, 200)"
                ></p>
                <div class="mt-1.5 flex items-center gap-1 text-gray-400 dark:text-gray-500">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    <span class="text-xs" x-text="domain"></span>
                </div>
            </div>
        </div>
    </div>
</div>
