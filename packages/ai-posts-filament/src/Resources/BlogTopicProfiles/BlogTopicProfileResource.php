<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages\CreateBlogTopicProfile;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages\EditBlogTopicProfile;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages\ListBlogTopicProfiles;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages\ViewBlogTopicProfile;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\RelationManagers\LessonsRelationManager;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Schemas\BlogTopicProfileForm;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Tables\BlogTopicProfilesTable;
use Core45\AiPosts\Models\BlogTopicProfile;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlogTopicProfileResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = BlogTopicProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?int $navigationSort = 1;

    /**
     * A package must not import a host enum: the consumer app names its own
     * admin navigation group through `ai.filament.navigation_group` (see
     * AIKnowledgeResource in ai-core-filament for the same convention).
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::resolveNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Topic Profiles');
    }

    public static function getModelLabel(): string
    {
        return __('Topic Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Topic Profiles');
    }

    public static function form(Schema $schema): Schema
    {
        return BlogTopicProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlogTopicProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LessonsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogTopicProfiles::route('/'),
            'create' => CreateBlogTopicProfile::route('/create'),
            'view' => ViewBlogTopicProfile::route('/{record}'),
            'edit' => EditBlogTopicProfile::route('/{record}/edit'),
        ];
    }
}
