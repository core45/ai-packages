<div class="space-y-4">
    @if(empty($detections))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No AI detection results yet. Click "Check for AI" to analyze this content.') }}
        </p>
    @else
        <div class="grid gap-3">
            @foreach($detections as $locale => $detection)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 uppercase">
                            {{ $locale }}
                        </span>
                        @php
                            $color = $detection->getScoreColor();
                            $colorClasses = match($color) {
                                'success' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                'danger' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colorClasses }}">
                            @if($detection->score !== null)
                                {{ number_format($detection->score, 0, ',', ' ') }}%
                                &mdash;
                            @endif
                            {{ $detection->getScoreLabel() }}
                        </span>
                    </div>

                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format($detection->word_count, 0, ',', ' ') }} {{ __('words') }}
                        &middot; {{ $detection->created_at?->diffForHumans() }}
                    </div>

                    @if($detection->result_details)
                        <details class="mt-2">
                            <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600 dark:hover:text-gray-300">
                                {{ __('Detector Breakdown') }}
                            </summary>
                            <div class="mt-1 grid grid-cols-2 gap-1 text-xs">
                                @foreach($detection->result_details as $key => $value)
                                    @if($key !== 'human')
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">{{ str_replace('score', '', $key) }}</span>
                                            <span class="font-mono">{{ number_format((float) $value, 0, ',', ' ') }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
