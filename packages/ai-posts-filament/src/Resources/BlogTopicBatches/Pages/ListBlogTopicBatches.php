<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches\Pages;

use Core45\AiPosts\Filament\Resources\BlogTopicBatches\BlogTopicBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListBlogTopicBatches extends ListRecords
{
    protected static string $resource = BlogTopicBatchResource::class;
}
