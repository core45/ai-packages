<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Core45\AiCore\Models\ContentArchetype;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only entry showing which {@see ContentArchetype} will actually drive
 * AI generation for the record being edited.
 *
 * It reads the live `content_archetype_id` form state first (so the answer
 * updates as soon as the select changes) and otherwise falls back to the
 * record's own `resolveContentArchetype()`, which is supplied by
 * `Core45\AiCore\Models\Concerns\HasContentArchetype`.
 *
 * Pair it with the `content_archetype_id` select — the field name it reads
 * is fixed, matching the column the trait's migration adds.
 */
class ResolvedArchetypeEntry
{
    public static function make(?string $name = null): TextEntry
    {
        return TextEntry::make($name ?? 'resolved_archetype')
            ->label(__('Effective Archetype'))
            ->state(function (?Model $record, Get $get): string {
                if (!$record) {
                    return __('Will be determined after saving');
                }

                // Check for direct assignment first (from live state)
                $directId = $get('content_archetype_id');
                if ($directId) {
                    /** @var ContentArchetype|null $archetype */
                    $archetype = ContentArchetype::query()->find($directId);
                    if ($archetype !== null) {
                        return $archetype->name.' '.__('(direct assignment)');
                    }
                }

                if (!method_exists($record, 'resolveContentArchetype')) {
                    return __('None - will use global AI settings');
                }

                // Use the model's resolution method
                $resolved = $record->resolveContentArchetype();
                if ($resolved) {
                    $source = $record->getAttribute('content_archetype_id') ? __('direct assignment') : __('system default');

                    return $resolved->name.' ('.$source.')';
                }

                return __('None - will use global AI settings');
            });
    }
}
