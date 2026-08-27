<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeGap\Pages;

use Core45\AiCore\Filament\Resources\AIKnowledgeGap\AIKnowledgeGapResource;
use Core45\AiCore\Filament\Resources\AIKnowledgeGap\Widgets\KnowledgeGapsStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListAIKnowledgeGaps extends ListRecords
{
    protected static string $resource = AIKnowledgeGapResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            KnowledgeGapsStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
