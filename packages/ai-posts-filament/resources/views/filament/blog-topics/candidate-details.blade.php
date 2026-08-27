<div class="space-y-4 p-4">
    @if(!empty($candidate->evidence_json))
        <div>
            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Evidence') }}</h4>
            <ul class="mt-1 space-y-2">
                @foreach($candidate->evidence_json as $evidence)
                    <li class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                        <span class="text-sm font-medium">{{ __('Source') }} #{{ $evidence['source_id'] ?? '?' }}</span>
                        <p class="text-sm">{{ $evidence['evidence_summary'] ?? '' }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!empty($candidate->risk_flags_json))
        <div>
            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Risk Flags') }}</h4>
            <pre class="mt-1 rounded-lg bg-yellow-50 p-2 text-sm dark:bg-yellow-900/20">{{ json_encode($candidate->risk_flags_json, JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endif
</div>
