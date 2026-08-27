<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Pages;

use Core45\AiCore\Filament\Resources\AIKnowledgeConflict\AIKnowledgeConflictResource;
use Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Widgets\KnowledgeConflictsStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListAIKnowledgeConflicts extends ListRecords
{
    protected static string $resource = AIKnowledgeConflictResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            KnowledgeConflictsStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
