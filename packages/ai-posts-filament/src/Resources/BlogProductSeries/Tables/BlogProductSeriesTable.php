<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\Tables;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Models\BlogProductSeries as BlogProductSeriesModel;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlogProductSeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label(__('ID')),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label(__('Name')),

                TextColumn::make('profile.name')
                    ->searchable()
                    ->label(__('Profile')),

                TextColumn::make('target_type')
                    ->badge()
                    ->label(__('Target Type')),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->label(__('Status')),

                TextColumn::make('posts_generated')
                    ->label(__('Posts'))
                    ->state(fn (BlogProductSeriesModel $record): string => $record->posts_generated.'/'.$record->total_posts),

                IconColumn::make('auto_publish')
                    ->boolean()
                    ->label(__('Auto Publish')),

                TextColumn::make('next_publish_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Next Publish')),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    // Same seam as BlogExternalLinksTable: the packaged enum does not
                    // implement Filament's HasLabel, because that would couple the
                    // headless ai-posts package to filament/support. Passing the class
                    // would render raw case names, so build the list explicitly.
                    ->options(fn (): array => collect(BlogProductSeriesStatus::cases())
                        ->mapWithKeys(fn (BlogProductSeriesStatus $case): array => [$case->value => $case->getLabel()])
                        ->all())
                    ->label(__('Status')),
            ]);
    }
}
