<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge\Pages;

use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAIKnowledge extends EditRecord
{
    protected static string $resource = AIKnowledgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
