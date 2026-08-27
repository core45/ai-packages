<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches;

use BackedEnum;
use Core45\AiPosts\Filament\Concerns\ResolvesNavigationGroup;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\Pages\ListBlogTopicBatches;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\Pages\ViewBlogTopicBatch;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\RelationManagers\SourcesRelationManager;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\RelationManagers\TopicCandidatesRelationManager;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\Tables\BlogTopicBatchesTable;
use Core45\AiPosts\Models\BlogTopicBatch;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlogTopicBatchResource extends Resource
{
    use ResolvesNavigationGroup;

    protected static ?string $model = BlogTopicBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 2;

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
        return __('Research Batches');
    }

    public static function getModelLabel(): string
    {
        return __('Research Batch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Research Batches');
    }

    public static function table(Table $table): Table
    {
        return BlogTopicBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TopicCandidatesRelationManager::class,
            SourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogTopicBatches::route('/'),
            'view' => ViewBlogTopicBatch::route('/{record}'),
        ];
    }
}
