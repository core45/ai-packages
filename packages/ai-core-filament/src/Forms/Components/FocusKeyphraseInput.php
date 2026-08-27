<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Core45\AiCore\Filament\Actions\AskAIAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class FocusKeyphraseInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Focus Keyphrase'))
            ->maxLength(100)
            ->placeholder(__('Enter your focus keyphrase'))
            ->helperText(__('The keyphrase you want this page to rank for.'))
            ->prefixIcon(Heroicon::OutlinedKey)
            ->live(onBlur: true)
            ->extraInputAttributes([
                'x-data' => '{}',
                'x-on:input' => 'updateCharCount($event)',
            ])
            ->hintAction(AskAIAction::make())
            ->hint(fn (mixed $state): ?string => is_string($state) && $state !== '' ? strlen($state).'/100' : null)
            ->columnSpanFull();
    }
}
