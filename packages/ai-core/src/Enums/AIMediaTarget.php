<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

enum AIMediaTarget: string
{
    case Gallery = 'gallery';
    case Description = 'description';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Gallery => __('Gallery Only'),
            self::Description => __('Description Only'),
            self::Both => __('Gallery & Description'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Gallery->value => self::Gallery->label(),
            self::Description->value => self::Description->label(),
            self::Both->value => self::Both->label(),
        ];
    }

    public function includesGallery(): bool
    {
        return $this === self::Gallery || $this === self::Both;
    }

    public function includesDescription(): bool
    {
        return $this === self::Description || $this === self::Both;
    }
}
