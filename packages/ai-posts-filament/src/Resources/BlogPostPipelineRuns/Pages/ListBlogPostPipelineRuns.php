<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Pages;

use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Filament\Resources\Pages\ListRecords;

class ListBlogPostPipelineRuns extends ListRecords
{
    protected static string $resource = BlogPostPipelineRunResource::class;
}
