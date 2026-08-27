<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages;

use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogTopicProfile extends CreateRecord
{
    protected static string $resource = BlogTopicProfileResource::class;
}
