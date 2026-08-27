<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

use Filament\Support\Contracts\HasLabel;

enum AIConflictStatus: int implements HasLabel
{
    case Detected = 0;
    case Reviewed = 1;
    case Resolved = 2;
    case Dismissed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Detected => __('ai_knowledge.conflict_status.detected'),
            self::Reviewed => __('ai_knowledge.conflict_status.reviewed'),
            self::Resolved => __('ai_knowledge.conflict_status.resolved'),
            self::Dismissed => __('ai_knowledge.conflict_status.dismissed'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function color(): string
    {
        return match ($this) {
            self::Detected => 'danger',
            self::Reviewed => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Detected => 'heroicon-o-exclamation-triangle',
            self::Reviewed => 'heroicon-o-eye',
            self::Resolved => 'heroicon-o-check-circle',
            self::Dismissed => 'heroicon-o-x-mark',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
