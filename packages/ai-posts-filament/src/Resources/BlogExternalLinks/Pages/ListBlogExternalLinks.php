<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages;

use Core45\AiPosts\Filament\Resources\BlogExternalLinks\BlogExternalLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogExternalLinks extends ListRecords
{
    protected static string $resource = BlogExternalLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
