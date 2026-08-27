<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\ContentModules;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\ContentModules\Pages\CreateContentModule;
use Core45\AiPosts\Filament\Resources\ContentModules\Pages\EditContentModule;
use Core45\AiPosts\Filament\Resources\ContentModules\Pages\ListContentModules;
use Core45\AiPosts\Filament\Resources\ContentModules\Schemas\ContentModuleForm;
use Core45\AiPosts\Filament\Resources\ContentModules\Tables\ContentModulesTable;
use Core45\AiPosts\Models\ContentModule;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContentModuleResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = ContentModule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 3;

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
        return __('Content Modules');
    }

    public static function getModelLabel(): string
    {
        return __('Content Module');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Content Modules');
    }

    public static function form(Schema $schema): Schema
    {
        return ContentModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentModulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentModules::route('/'),
            'create' => CreateContentModule::route('/create'),
            'edit' => EditContentModule::route('/{record}/edit'),
        ];
    }
}
