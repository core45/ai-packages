<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

use Filament\Support\Contracts\HasLabel;

enum AIKnowledgeType: string implements HasLabel
{
    case Instruction = 'instruction';
    case Fact = 'fact';
    case Faq = 'faq';
    case Forbidden = 'forbidden';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::Instruction => __('ai_knowledge.type.instruction'),
            self::Fact => __('ai_knowledge.type.fact'),
            self::Faq => __('ai_knowledge.type.faq'),
            self::Forbidden => __('ai_knowledge.type.forbidden'),
            self::Required => __('ai_knowledge.type.required'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Instruction => 'heroicon-o-document-text',
            self::Fact => 'heroicon-o-building-office',
            self::Faq => 'heroicon-o-question-mark-circle',
            self::Forbidden => 'heroicon-o-x-circle',
            self::Required => 'heroicon-o-check-circle',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Instruction => 'info',
            self::Fact => 'gray',
            self::Faq => 'success',
            self::Forbidden => 'danger',
            self::Required => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
