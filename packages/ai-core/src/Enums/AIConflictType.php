<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

enum AIConflictType: int
{
    case DirectContradiction = 0;
    case PartialOverlap = 1;
    case Ambiguous = 2;

    public function label(): string
    {
        return match ($this) {
            self::DirectContradiction => __('ai_knowledge.conflict_type.direct_contradiction'),
            self::PartialOverlap => __('ai_knowledge.conflict_type.partial_overlap'),
            self::Ambiguous => __('ai_knowledge.conflict_type.ambiguous'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function color(): string
    {
        return match ($this) {
            self::DirectContradiction => 'danger',
            self::PartialOverlap => 'warning',
            self::Ambiguous => 'info',
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
