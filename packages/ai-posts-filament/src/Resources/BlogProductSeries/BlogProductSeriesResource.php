<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages\CreateBlogProductSeries;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages\ListBlogProductSeries;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages\ViewBlogProductSeries;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\RelationManagers\CandidatesRelationManager;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\Schemas\BlogProductSeriesForm;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\Tables\BlogProductSeriesTable;
use Core45\AiPosts\Models\BlogProductSeries as BlogProductSeriesModel;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlogProductSeriesResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = BlogProductSeriesModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 3;

    /**
     * The host's own navigation taxonomy, reached through config rather than
     * owned — this used to be `App\Enums\NavigationGroup::BlogTopics`, which a
     * package cannot name. `ResolvesNavigationGroup` walks
     * `ai-posts.filament.navigation_group`, then `ai.filament.navigation_group`,
     * then a plain 'AI' default, so an app that has already named its AI group
     * gets this resource under it without configuring anything.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::resolveNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Product Series');
    }

    public static function getModelLabel(): string
    {
        return __('Product Series');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Product Series');
    }

    public static function form(Schema $schema): Schema
    {
        return BlogProductSeriesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlogProductSeriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CandidatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogProductSeries::route('/'),
            'create' => CreateBlogProductSeries::route('/create'),
            'view' => ViewBlogProductSeries::route('/{record}'),
        ];
    }
}
