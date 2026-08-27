<?php

namespace Core45\AiCore\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

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

                // No shared Filament interface exposes getRecord() (it's
                // declared separately on each resource page class), so keep
                // the method_exists() check to stay host-page-agnostic, but
                // guard with is_object() first so PHPStan can type the call
                // (the untyped $livewire closure param is class-string|object)
                // and confirm the result is a real Model before using it.
                if (is_object($livewire) && method_exists($livewire, 'getRecord')) {
                    $record = $livewire->getRecord();
                    if ($record instanceof Model) {
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
