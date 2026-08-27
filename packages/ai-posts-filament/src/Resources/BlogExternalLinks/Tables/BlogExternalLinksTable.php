<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\Tables;

use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlogExternalLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label(__('ID')),

                TextColumn::make('domain')
                    ->searchable()
                    ->sortable()
                    ->label(__('Domain')),

                TextColumn::make('label')
                    ->searchable()
                    ->limit(40)
                    ->label(__('Label')),

                TextColumn::make('url')
                    ->limit(50)
                    ->label(__('URL'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->label(__('Status')),

                TextColumn::make('profile.name')
                    ->placeholder(__('Global'))
                    ->label(__('Profile')),

                TextColumn::make('usage_count')
                    ->sortable()
                    ->label(__('Usages')),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    // Same seam as BlogExternalLinkForm: no HasLabel on the enum, so
                    // build the option list explicitly rather than passing the class.
                    ->options(fn (): array => collect(BlogExternalLinkStatus::cases())
                        ->mapWithKeys(fn (BlogExternalLinkStatus $case): array => [$case->value => $case->getLabel()])
                        ->all())
                    ->label(__('Status')),
            ]);
    }
}
