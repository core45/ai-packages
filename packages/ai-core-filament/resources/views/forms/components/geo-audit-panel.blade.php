{{--
    D7: `GeoProfile::$grade` is a `Core45\AiCore\Enums\GeoGrade` again, so the
    colour and the label come from the enum's own `color()` / `label()` helpers
    rather than from the two inline maps that stood in for them while the grade
    was a plain string. Those maps are deliberately gone: duplicating a mapping
    the enum already owns is how the two drift, and they already had — the enum
    reads 'Needs Work' and 'Critical' where these said 'Fair' and 'Failing'.

    Still nullable: a record that has never been scored has no profile at all,
    so both reads go through `?->` and fall back to the same neutral badge the
    string version showed.
--}}
@php
    $record = $getRecord();
    $locale = $getLocale() ?? app()->getLocale();
    $profile = $record?->geoProfile($locale);

    $grade = $profile?->grade;
    $gradeColor = $grade?->color() ?? 'gray';
@endphp

<div class="space-y-4">
    @if($profile)
        <div class="flex items-center gap-3">
            <x-filament::badge :color="$gradeColor" size="lg">
                {{ $grade?->value }} - {{ $grade?->label() }}
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
