<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BlogTopicProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label(__('Name')),

                TextColumn::make('last_refreshed_at')
                    ->since()
                    ->sortable()
                    ->label(__('Last Refreshed')),

                TextColumn::make('batches_count')
                    ->counts('batches')
                    ->sortable()
                    ->label(__('Batches')),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created')),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
