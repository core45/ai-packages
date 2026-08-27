<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages;

use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogTopicProfiles extends ListRecords
{
    protected static string $resource = BlogTopicProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
