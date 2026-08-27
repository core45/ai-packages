<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Core45\AiCore\Filament\Actions\AskAIAction;
use Filament\Forms\Components\TextInput;

class SeoTitleInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('SEO Title'))
            ->placeholder(__('Enter SEO title (50-60 characters)'))
            ->live(onBlur: true)
            ->columnSpanFull()
            ->hintAction(AskAIAction::make())
            ->hint(fn ($state) => self::characterCountHint($state, 50, 60))
            ->hintColor(fn ($state) => self::hintColorForLength($state, 50, 60));
    }

    /**
     * Get the character count hint string.
     */
    public static function characterCountHint(?string $state, int $optimalMin, int $optimalMax): string
    {
        $length = $state ? mb_strlen($state) : 0;

        return $length.'/'.$optimalMax.' '.__('characters');
    }

    /**
     * Get the hint color based on character length relative to optimal range.
     */
    public static function hintColorForLength(?string $state, int $optimalMin, int $optimalMax): string
    {
        $length = $state ? mb_strlen($state) : 0;

        if ($length === 0) {
            return 'gray';
        }

        if ($length >= $optimalMin && $length <= $optimalMax) {
            return 'success';
        }

        if ($length >= ($optimalMin - 10) && $length <= ($optimalMax + 5)) {
            return 'warning';
        }

        return 'danger';
    }
}
