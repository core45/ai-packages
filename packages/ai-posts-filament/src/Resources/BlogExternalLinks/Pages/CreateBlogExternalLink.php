<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages;

use Core45\AiPosts\Filament\Resources\BlogExternalLinks\BlogExternalLinkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogExternalLink extends CreateRecord
{
    protected static string $resource = BlogExternalLinkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['url_hash'] = hash('sha256', $data['url']);

        if (empty($data['domain'])) {
            $data['domain'] = (string) parse_url($data['url'], PHP_URL_HOST);
        }

        return $data;
    }
}
