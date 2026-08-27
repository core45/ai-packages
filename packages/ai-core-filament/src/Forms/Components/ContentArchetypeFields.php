<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Models\ContentArchetype;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;

/**
 * The content-archetype pair for a host resource form: the picker plus the
 * read-only entry showing which archetype will actually drive generation.
 *
 * Bundled together because they are always placed side by side and the
 * entry's live state depends on the select's field name.
 *
 * The select's options are scoped to the given model via {@see ModelRegistry}
 * so no package (and no host form) has to name a host model class or its
 * `applicable_model` string literal directly: the registry key is resolved
 * to the host model class, and `class_basename()` reproduces the same
 * literal `ContentArchetype::scopeForModel()` derives it to (e.g. `'Post'`
 * for the host's `post` registration), matching what `applicable_model` rows
 * are seeded with.
 *
 * @return list<Component>
 */
class ContentArchetypeFields
{
    /**
     * @return array<int, Component>
     */
    public static function make(string $modelRegistryKey = 'post'): array
    {
        // Throw rather than fall back to the raw key: `applicable_model` rows
        // store the class basename ('Post'), so a lowercase key ('post') would
        // match nothing and silently render an empty archetype list.
        $modelClass = app(ModelRegistry::class)->getModelClass($modelRegistryKey)
            ?? throw new \RuntimeException(
                "ContentArchetypeFields: no '{$modelRegistryKey}' entry in config('ai.models')."
            );

        $applicableModel = class_basename($modelClass);

        return [
            Select::make('content_archetype_id')
                ->label(__('Content Archetype'))
                ->helperText(__('Select a content archetype to customize AI-generated content style.'))
                ->options(fn () => ContentArchetype::query()
                    ->where('applicable_model', $applicableModel)
                    ->where('active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->nullable()
                ->live(),
            ResolvedArchetypeEntry::make(),
        ];
    }
}
