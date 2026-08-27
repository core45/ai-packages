<div class="space-y-4 p-4">
    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Type') }}</span>
        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $lesson->lesson_type }}</p>
    </div>

    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Lesson Text') }}</span>
        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $lesson->lesson_text }}</p>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Confidence') }}</span>
            <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ number_format((float) $lesson->confidence, 2, ',', ' ') }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Effectiveness') }}</span>
            <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $lesson->effectiveness_score !== null ? number_format((float) $lesson->effectiveness_score, 2, ',', ' ') : '-' }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Times Applied') }}</span>
            <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $lesson->times_applied }}</p>
        </div>
    </div>

    @if($lesson->source_feedback_ids)
    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Source Feedback IDs') }}</span>
        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ implode(', ', $lesson->source_feedback_ids) }}</p>
    </div>
    @endif

    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Active') }}</span>
        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $lesson->active ? __('Yes') : __('No') }}</p>
    </div>
</div>
