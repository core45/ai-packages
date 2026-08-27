<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

use Filament\Support\Contracts\HasLabel;

enum AIFeedbackRating: string implements HasLabel
{
    case Positive = 'positive';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Positive => __('Helpful'),
            self::Negative => __('Not helpful'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Positive => 'heroicon-s-hand-thumb-up',
            self::Negative => 'heroicon-s-hand-thumb-down',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Positive->value => self::Positive->label(),
            self::Negative->value => self::Negative->label(),
        ];
    }
}
