<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches\Tables;

use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlogTopicBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label(__('ID')),

                TextColumn::make('profile.name')
                    ->searchable()
                    ->label(__('Profile')),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->label(__('Status')),

                TextColumn::make('candidates_count')
                    ->counts('candidates')
                    ->sortable()
                    ->label(__('Topics')),

                TextColumn::make('sources_count')
                    ->counts('sources')
                    ->sortable()
                    ->label(__('Sources')),

                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Started')),

                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Finished')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    // BlogTopicBatchStatus does not implement Filament's
                    // HasLabel (removed deliberately, and it has no
                    // `options()` static), so the value => label map is
                    // built explicitly here instead of relying on
                    // Filament's enum auto-detection.
                    ->options(fn (): array => collect(BlogTopicBatchStatus::cases())
                        ->mapWithKeys(fn (BlogTopicBatchStatus $case): array => [$case->value => $case->getLabel()])
                        ->all()),
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
