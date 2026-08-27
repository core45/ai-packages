<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\BlogProductSeriesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogProductSeries extends CreateRecord
{
    protected static string $resource = BlogProductSeriesResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = BlogProductSeriesStatus::Planning->value;
        $data['target_ids'] = [];
        $data['posts_generated'] = 0;

        return $data;
    }
}
