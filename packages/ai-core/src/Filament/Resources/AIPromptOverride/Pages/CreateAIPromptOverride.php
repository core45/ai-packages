<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride\Pages;

use Core45\AiCore\Filament\Resources\AIPromptOverride\AIPromptOverrideResource;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Schemas\AIPromptOverrideForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class CreateAIPromptOverride extends CreateRecord
{
    protected static string $resource = AIPromptOverrideResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function form(Schema $schema): Schema
    {
        return AIPromptOverrideForm::configure($schema);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure enabled is set
        if (!isset($data['enabled'])) {
            $data['enabled'] = true;
        }

        return $data;
    }
}
