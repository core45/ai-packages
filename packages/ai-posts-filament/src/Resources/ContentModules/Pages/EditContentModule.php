<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\ContentModules\Pages;

use Core45\AiPosts\Filament\Resources\ContentModules\ContentModuleResource;
use Core45\AiPosts\Models\ContentModule;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditContentModule extends EditRecord
{
    protected static string $resource = ContentModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (ContentModule $record, DeleteAction $action): void {
                    if ($record->is_built_in) {
                        Notification::make()
                            ->title(__('Cannot delete built-in module'))
                            ->body(__('Built-in modules are part of the system and cannot be deleted.'))
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
