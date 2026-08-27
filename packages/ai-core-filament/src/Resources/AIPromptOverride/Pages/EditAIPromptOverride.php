<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride\Pages;

use Core45\AiCore\Filament\Resources\AIPromptOverride\AIPromptOverrideResource;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Schemas\AIPromptOverrideForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class EditAIPromptOverride extends EditRecord
{
    protected static string $resource = AIPromptOverrideResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function form(Schema $schema): Schema
    {
        return AIPromptOverrideForm::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
