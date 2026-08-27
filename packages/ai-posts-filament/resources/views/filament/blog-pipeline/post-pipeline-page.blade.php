<x-filament-panels::page>
    @if($pipelineRun)
        <div class="space-y-6">
            {{-- Pipeline Run Summary --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Pipeline Run Summary') }}</x-slot>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Status') }}</span>
                        <div class="mt-1">
                            <x-filament::badge :color="$pipelineRun->status->getColor()">
                                {{ $pipelineRun->status->getLabel() }}
                            </x-filament::badge>
                        </div>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Total Cost') }}</span>
                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ number_format((float) $pipelineRun->total_cost, 4, ',', ' ') }} EUR</p>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Started') }}</span>
                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $pipelineRun->started_at?->format('d/m/Y H:i') ?? '-' }}</p>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Completed') }}</span>
                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $pipelineRun->completed_at?->format('d/m/Y H:i') ?? '-' }}</p>
                    </div>
                </div>
            </x-filament::section>

            {{-- Module Outputs Summary --}}
            @if(count($moduleOutputs) > 0)
                <x-filament::section>
                    <x-slot name="heading">{{ __('Module Outputs') }}</x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-2">{{ __('Module') }}</th>
                                    <th class="px-4 py-2">{{ __('Quality Score') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($moduleOutputs as $output)
                                    <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                                        <td class="px-4 py-2">
                                            <x-filament::badge color="info">{{ $output['slug'] }}</x-filament::badge>
                                        </td>
                                        <td class="px-4 py-2">{{ $output['quality_score'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            {{-- Feedback History --}}
            @if($feedbacks->count() > 0)
                <x-filament::section>
                    <x-slot name="heading">{{ __('Feedback History') }}</x-slot>

                    <div class="space-y-4">
                        @foreach($feedbacks as $feedback)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <x-filament::badge color="gray">{{ $feedback->feedback_type }}</x-filament::badge>
                                        @if($feedback->module_slug)
                                            <x-filament::badge color="info">{{ $feedback->module_slug }}</x-filament::badge>
                                        @endif
                                        <span class="text-sm text-gray-500">{{ __('Rating') }}: {{ $feedback->rating }}/5</span>
                                    </div>
                                    <span class="text-xs text-gray-400">
                                        {{ $feedback->user?->name ?? __('System') }}
                                        &middot;
                                        {{ $feedback->created_at?->diffForHumans() }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $feedback->comment }}</p>
                                @if($feedback->action_requested && $feedback->action_requested !== 'none')
                                    <p class="mt-1 text-xs text-gray-500">{{ __('Action requested') }}: {{ $feedback->action_requested }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-500">{{ __('No pipeline run found for this post.') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
