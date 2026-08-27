<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge\Pages;

use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAIKnowledge extends ListRecords
{
    protected static string $resource = AIKnowledgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
