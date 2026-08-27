<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Pages\ListBlogPostPipelineRuns;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Pages\ViewBlogPostPipelineRun;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Tables\BlogPostPipelineRunsTable;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlogPostPipelineRunResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = BlogPostPipelineRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog;

    protected static ?int $navigationSort = 4;

    /**
     * A plain, config-driven string rather than the host's
     * the host's `NavigationGroup` backed enum — this package holds no
     * reference to a host namespace.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::resolveNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Pipeline Runs');
    }

    public static function getModelLabel(): string
    {
        return __('Pipeline Run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Pipeline Runs');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BlogPostPipelineRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogPostPipelineRuns::route('/'),
            'view' => ViewBlogPostPipelineRun::route('/{record}'),
        ];
    }
}
