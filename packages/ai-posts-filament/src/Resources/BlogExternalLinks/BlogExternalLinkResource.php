<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages\CreateBlogExternalLink;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages\ListBlogExternalLinks;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages\ViewBlogExternalLink;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\RelationManagers\ReviewsRelationManager;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\RelationManagers\UsagesRelationManager;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\Schemas\BlogExternalLinkForm;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\Tables\BlogExternalLinksTable;
use Core45\AiPosts\Models\BlogExternalLink;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlogExternalLinkResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = BlogExternalLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 4;

    /**
     * A plain, config-driven string rather than the host's `NavigationGroup`
     * backed enum — this package holds no reference to a host namespace.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::resolveNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('External Links');
    }

    public static function getModelLabel(): string
    {
        return __('External Link');
    }

    public static function getPluralModelLabel(): string
    {
        return __('External Links');
    }

    public static function form(Schema $schema): Schema
    {
        return BlogExternalLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlogExternalLinksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
            ReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogExternalLinks::route('/'),
            'create' => CreateBlogExternalLink::route('/create'),
            'view' => ViewBlogExternalLink::route('/{record}'),
        ];
    }
}
