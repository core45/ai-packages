<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

enum AIImprovementStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case NeedsDevelopment = 'needs_development';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending Review'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Applied => __('Applied'),
            self::NeedsDevelopment => __('Needs Development'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check-circle',
            self::Rejected => 'heroicon-o-x-circle',
            self::Applied => 'heroicon-o-check-badge',
            self::NeedsDevelopment => 'heroicon-o-code-bracket',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Applied => 'success',
            self::NeedsDevelopment => 'gray',
        };
    }

    /**
     * Check if the suggestion can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Check if the suggestion can be applied.
     */
    public function canBeApplied(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Pending->value => self::Pending->label(),
            self::Approved->value => self::Approved->label(),
            self::Rejected->value => self::Rejected->label(),
            self::Applied->value => self::Applied->label(),
            self::NeedsDevelopment->value => self::NeedsDevelopment->label(),
        ];
    }
}
