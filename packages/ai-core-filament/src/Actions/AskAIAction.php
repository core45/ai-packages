<?php

namespace Core45\AiCore\Filament\Actions;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class AskAIAction
{
    /**
     * Create an "Ask AI" hint action for a form field.
     * When clicked, opens the AI chat panel with the field context.
     */
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'askAI')
            ->iconButton()
            ->tooltip(__('Ask AI about this field'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->size('xs')
            ->extraAttributes([
                'class' => 'ai-ask-action',
            ])
            ->action(function ($component, $state, $livewire) {
                $statePath = $component->getStatePath();
                $fieldLabel = $component->getLabel();

                // Extract locale from statePath for translatable fields
                $locale = static::extractLocaleFromStatePath($statePath);
                $fieldName = static::extractFieldNameFromStatePath($statePath);

                // Get current content
                $currentContent = $state ?? '';

                // Get model context from Livewire component
                $modelType = null;
                $modelId = null;

                // Only the Filament resource pages that own a record declare
                // getRecord(); no shared interface exposes it, so narrow via
                // instanceof against the concrete page classes instead of a
                // method_exists() check PHPStan cannot use to type the call.
                if (
                    $livewire instanceof CreateRecord
                    || $livewire instanceof EditRecord
                    || $livewire instanceof ViewRecord
                    || $livewire instanceof ManageRelatedRecords
                ) {
                    $record = $livewire->getRecord();
                    if ($record) {
                        $modelType = get_class($record);
                        $modelId = $record->getKey();
                    }
                }

                // Dispatch browser event to open AI chat with context
                $livewire->dispatch('open-ai-chat-with-field', [
                    'statePath' => $statePath,
                    'fieldName' => $fieldName,
                    'fieldLabel' => $fieldLabel,
                    'locale' => $locale,
                    'currentContent' => is_string($currentContent) ? $currentContent : '',
                    'modelType' => $modelType,
                    'modelId' => $modelId,
                ]);
            })
            ->visible(fn () => config('ai.assistant.enabled', false));
    }

    /**
     * Extract locale from statePath like "data.es.description" or "es.description"
     */
    protected static function extractLocaleFromStatePath(string $statePath): ?string
    {
        // Get configured locales from laravellocalization config
        $supportedLocales = array_keys(config('laravellocalization.supportedLocales', []));

        // Split the path and look for a locale segment
        $segments = explode('.', $statePath);

        foreach ($segments as $segment) {
            if (in_array($segment, $supportedLocales, true)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Extract field name from statePath like "data.es.description" -> "description"
     */
    protected static function extractFieldNameFromStatePath(string $statePath): string
    {
        $segments = explode('.', $statePath);

        return end($segments) ?: $statePath;
    }
}
