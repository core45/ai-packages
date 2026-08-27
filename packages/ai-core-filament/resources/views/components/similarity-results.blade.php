<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Similarity analysis for :model', ['model' => $modelName]) }}
    </p>

    @if(count($results) === 0)
        <div class="rounded-lg bg-success-50 p-4 dark:bg-success-400/10">
            <div class="flex">
                <div class="flex-shrink-0">
                    <x-heroicon-o-check-circle class="h-5 w-5 text-success-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-success-800 dark:text-success-400">
                        {{ __('No similarity issues found') }}
                    </h3>
                    <div class="mt-2 text-sm text-success-700 dark:text-success-300">
                        <p>{{ __('Content appears to be unique across all fields and locales.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Field') }}
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Locale') }}
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Method') }}
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Similarity') }}
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Similar To') }}
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Status') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                    @foreach($results as $result)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                                    {{ ucfirst(str_replace('_', ' ', $result['field'])) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                    {{ strtoupper($result['locale']) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                                        Jaccard
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @php
                                    $score = $result['score'] * 100;
                                    $colorClass = match(true) {
                                        $score >= 75 => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
                                        $score >= 60 => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
                                        default => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
                                    };
                                @endphp
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Lexical:</span>
                                        <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $colorClass }}">
                                            {{ number_format($score, 1) }}%
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                {{ Str::limit($result['similar_to'], 30) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                @php
                                    $statusColor = match($result['status']) {
                                        'failed' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
                                        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
                                        default => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                                    {{ ucfirst($result['status']) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
            <div class="space-y-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <strong>{{ __('Thresholds:') }}</strong>
                    <span class="ml-2 inline-flex items-center rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400">{{ __('Pass') }}</span> &lt; 60%,
                    <span class="ml-2 inline-flex items-center rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">{{ __('Warning') }}</span> 60-75%,
                    <span class="ml-2 inline-flex items-center rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">{{ __('Failed') }}</span> &gt; 75%
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <strong>{{ __('Methods:') }}</strong>
                    <span class="ml-2">{{ __('Lexical (Jaccard): word-level matching for exact duplicates') }}</span>
                </p>
            </div>
        </div>
    @endif
</div>
