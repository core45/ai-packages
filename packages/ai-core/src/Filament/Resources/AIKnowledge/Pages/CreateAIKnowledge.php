<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge\Pages;

use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAIKnowledge extends CreateRecord
{
    protected static string $resource = AIKnowledgeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['source_type'] = 'manual';

        return $data;
    }
}
