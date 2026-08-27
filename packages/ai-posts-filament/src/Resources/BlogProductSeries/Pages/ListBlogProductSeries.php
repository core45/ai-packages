<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages;

use Core45\AiPosts\Filament\Resources\BlogProductSeries\BlogProductSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogProductSeries extends ListRecords
{
    protected static string $resource = BlogProductSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
