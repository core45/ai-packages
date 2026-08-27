<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\ContentModules\Tables;

use Core45\AiPosts\Models\ContentModule;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContentModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('output_format')
                    ->label(__('Format'))
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_built_in')
                    ->label(__('Built-in'))
                    ->boolean()
                    ->sortable(),

                ToggleColumn::make('active')
                    ->label(__('Active'))
                    ->sortable(),

                TextColumn::make('word_count_range')
                    ->label(__('Word Count'))
                    ->state(function (ContentModule $record): string {
                        $criteria = $record->quality_criteria;
                        if ($criteria === null) {
                            return '-';
                        }

                        $min = $criteria['min_words'] ?? null;
                        $max = $criteria['max_words'] ?? null;

                        if ($min === null && $max === null) {
                            return '-';
                        }

                        return ($min ?? '?').' - '.($max ?? '?');
                    })
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                TernaryFilter::make('is_built_in')
                    ->label(__('Built-in')),

                TernaryFilter::make('active')
                    ->label(__('Active')),

                SelectFilter::make('output_format')
                    ->label(__('Format'))
                    ->options([
                        'html' => 'HTML',
                        'markdown' => 'Markdown',
                        'json' => 'JSON',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (ContentModule $record, DeleteAction $action): void {
                        if ($record->is_built_in) {
                            Notification::make()
                                ->title(__('Cannot delete built-in module'))
                                ->body(__('Built-in modules are part of the system and cannot be deleted.'))
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
