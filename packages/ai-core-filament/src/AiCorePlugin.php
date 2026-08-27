<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament;

use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Core45\AiCore\Filament\Resources\AIKnowledgeConflict\AIKnowledgeConflictResource;
use Core45\AiCore\Filament\Resources\AIKnowledgeGap\AIKnowledgeGapResource;
use Core45\AiCore\Filament\Resources\AIPromptOverride\AIPromptOverrideResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registers the ai-core knowledge/prompt Filament resources on a panel. The
 * consuming app opts in via `$panel->plugin(AiCorePlugin::make())` — resource
 * URLs, slugs and navigation groups are identical to the pre-extraction
 * host-owned layout, so no links or permissions change.
 */
class AiCorePlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'ai-core';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            AIPromptOverrideResource::class,
            AIKnowledgeResource::class,
            AIKnowledgeConflictResource::class,
            AIKnowledgeGapResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
