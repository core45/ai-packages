<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages;

use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBlogTopicProfile extends EditRecord
{
    protected static string $resource = BlogTopicProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
