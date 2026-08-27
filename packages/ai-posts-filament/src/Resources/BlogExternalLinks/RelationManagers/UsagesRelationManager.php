<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Link Usages');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('post.id')
                    ->label(__('Post ID'))
                    ->sortable(),

                TextColumn::make('anchor_text')
                    ->searchable()
                    ->limit(50)
                    ->label(__('Anchor Text')),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->label(__('Status')),

                TextColumn::make('placed_by')
                    ->badge()
                    ->label(__('Placed By')),

                TextColumn::make('suggested_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Suggested')),

                TextColumn::make('accepted_at')
                    ->dateTime()
                    ->label(__('Accepted')),
            ])
            ->defaultSort('suggested_at', 'desc');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
