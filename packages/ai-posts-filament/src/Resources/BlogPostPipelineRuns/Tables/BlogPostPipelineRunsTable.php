<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Tables;

use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlogPostPipelineRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidate.title')
                    ->label(__('Candidate'))
                    ->searchable()
                    ->limit(50)
                    ->sortable(),

                TextColumn::make('profile.name')
                    ->label(__('Profile'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('current_step')
                    ->label(__('Current Step'))
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('total_cost')
                    ->label(__('Cost'))
                    ->state(fn (BlogPostPipelineRun $record): string => number_format((float) $record->total_cost, 4, ',', ' ').' EUR')
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label(__('Completed'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    // Same seam as BlogExternalLinksTable: the packaged enum does not
                    // implement Filament's HasLabel, because that would couple the
                    // headless ai-posts package to filament/support. Passing the class
                    // would render raw case names, so build the list explicitly.
                    ->options(fn (): array => collect(BlogPostPipelineStatus::cases())
                        ->mapWithKeys(fn (BlogPostPipelineStatus $case): array => [$case->value => $case->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
