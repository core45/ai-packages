<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

enum AIImprovementType: string
{
    case Knowledge = 'knowledge';
    case Prompt = 'prompt';
    case Tool = 'tool';

    public function label(): string
    {
        return match ($this) {
            self::Knowledge => __('Knowledge Base'),
            self::Prompt => __('System Prompt'),
            self::Tool => __('Tool/Function'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Knowledge => 'heroicon-o-light-bulb',
            self::Prompt => 'heroicon-o-document-text',
            self::Tool => 'heroicon-o-wrench-screwdriver',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Knowledge => 'success',
            self::Prompt => 'warning',
            self::Tool => 'danger',
        };
    }

    /**
     * Risk level for auto-apply decisions.
     */
    public function riskLevel(): string
    {
        return match ($this) {
            self::Knowledge => 'low',
            self::Prompt => 'medium',
            self::Tool => 'high',
        };
    }

    /**
     * Threshold for pattern detection notifications.
     */
    public function notificationThreshold(): int
    {
        return match ($this) {
            self::Knowledge => 3,
            self::Prompt => 5,
            self::Tool => 2,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Knowledge->value => self::Knowledge->label(),
            self::Prompt->value => self::Prompt->label(),
            self::Tool->value => self::Tool->label(),
        ];
    }
}
