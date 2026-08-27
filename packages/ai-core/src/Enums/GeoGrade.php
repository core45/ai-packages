<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

/**
 * Letter grade derived from a GEO (Generative Engine Optimization) score.
 *
 * Deliberately implements NO Filament contract. The source application's enum
 * was `enum GeoGrade: string implements HasLabel`, which would make
 * `Filament\Support\Contracts\HasLabel` a hard requirement of `ai-core` — a
 * domain package that installs into apps with no panel at all. Same call as
 * {@see NotificationSeverity}, which drops the same coupling: the enum exposes
 * plain `label()` and `color()` helpers, and the Filament side reads them
 * (or `->value`) rather than the framework reading the enum through an
 * interface.
 *
 * `label()` uses `__()`, which is Laravel's translator, not Filament's, so the
 * strings still localise in a headless install.
 */
enum GeoGrade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case F = 'F';

    /**
     * Thresholds are the source application's, unchanged: 85 / 70 / 55 / 40.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 85 => self::A,
            $score >= 70 => self::B,
            $score >= 55 => self::C,
            $score >= 40 => self::D,
            default => self::F,
        };
    }

    /**
     * A presentation hint, not a delivery instruction — the four strings happen
     * to be Filament's badge colours, but nothing here depends on that.
     */
    public function color(): string
    {
        return match ($this) {
            self::A => 'success',
            self::B => 'info',
            self::C, self::D => 'warning',
            self::F => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::A => __('Excellent'),
            self::B => __('Good'),
            self::C => __('Needs Work'),
            self::D => __('Poor'),
            self::F => __('Critical'),
        };
    }
}
