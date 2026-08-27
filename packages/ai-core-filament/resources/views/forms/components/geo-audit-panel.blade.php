{{--
    NOTE: in the source application `GeoProfile::$grade` was a host-owned
    `GeoGrade` backed enum with `color()`/`label()` helpers. The host enums
    directory is out of scope for this port, so the host `GeoProfile` model
    stores `grade` as a plain string ('A'..'F') and the color/label mapping
    below is done inline instead.
--}}
@php
    $record = $getRecord();
    $locale = $getLocale() ?? app()->getLocale();
    $profile = $record?->geoProfile($locale);

    $gradeColor = match ($profile?->grade) {
        'A' => 'success',
        'B' => 'info',
        'C' => 'warning',
        'D', 'F' => 'danger',
        default => 'gray',
    };

    $gradeLabels = [
        'A' => __('Excellent'),
        'B' => __('Good'),
        'C' => __('Fair'),
        'D' => __('Poor'),
        'F' => __('Failing'),
    ];
@endphp

<div class="space-y-4">
    @if($profile)
        <div class="flex items-center gap-3">
            <x-filament::badge :color="$gradeColor" size="lg">
                {{ $profile->grade }} - {{ $gradeLabels[$profile->grade] ?? $profile->grade }}
            </x-filament::badge>
            <span class="text-lg font-bold">{{ $profile->score }}/100</span>
        </div>

        @if($profile->audit)
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b dark:border-gray-700">
                        <th class="py-2 text-left">{{ __('Signal') }}</th>
                        <th class="py-2 text-center">{{ __('Weight') }}</th>
                        <th class="py-2 text-center">{{ __('Status') }}</th>
                        <th class="py-2 text-left">{{ __('Tip') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($profile->audit as $signal)
                        <tr class="border-b dark:border-gray-700">
                            <td class="py-2">{{ str_replace('_', ' ', ucfirst($signal['signal'])) }}</td>
                            <td class="py-2 text-center">{{ $signal['weight'] }}</td>
                            <td class="py-2 text-center">
                                @if($signal['passed'])
                                    <x-filament::badge color="success">&#10003;</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger">&#10007;</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">{{ $signal['tip'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <div class="text-center text-gray-500 dark:text-gray-400 py-4">
            {{ __('Not scored yet.') }}
        </div>
    @endif
</div>
