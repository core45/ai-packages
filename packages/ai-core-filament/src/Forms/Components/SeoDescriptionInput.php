<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Core45\AiCore\Filament\Actions\AskAIAction;
use Filament\Forms\Components\Textarea;

class SeoDescriptionInput extends Textarea
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('SEO Description'))
            ->rows(3)
            ->placeholder(__('Enter meta description (120-156 characters)'))
            ->live(onBlur: true)
            ->columnSpanFull()
            ->hintAction(AskAIAction::make())
            ->hint(fn ($state) => SeoTitleInput::characterCountHint($state, 120, 156))
            ->hintColor(fn ($state) => SeoTitleInput::hintColorForLength($state, 120, 156));
    }
}
