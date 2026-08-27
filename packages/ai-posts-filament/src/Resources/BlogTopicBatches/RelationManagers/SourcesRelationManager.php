<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sources');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->limit(50)
                    ->label(__('Title')),

                TextColumn::make('domain')
                    ->searchable()
                    ->label(__('Domain')),

                TextColumn::make('url')
                    ->limit(60)
                    ->url(fn ($record): string => $record->url, shouldOpenInNewTab: true)
                    ->label(__('URL')),

                TextColumn::make('snippet')
                    ->limit(80)
                    ->label(__('Snippet'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reliability_score')
                    ->sortable()
                    ->label(__('Reliability')),

                TextColumn::make('language')
                    ->label(__('Language')),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
