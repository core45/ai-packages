<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\ContentModules\Pages;

use Core45\AiPosts\Filament\Resources\ContentModules\ContentModuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentModule extends CreateRecord
{
    protected static string $resource = ContentModuleResource::class;
}
