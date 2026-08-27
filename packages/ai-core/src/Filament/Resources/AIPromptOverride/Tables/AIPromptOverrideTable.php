<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride\Tables;

use Core45\AiCore\Models\AIPromptOverride;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class AIPromptOverrideTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prompt_key')
                    ->label(__('ai_prompt_override.field.prompt_key'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("ai_prompt_override.prompt_key.{$state}"))
                    ->color(fn (string $state): string => AIPromptOverride::promptKeyBadgeColor($state))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('change_type')
                    ->label(__('ai_prompt_override.field.change_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("ai_prompt_override.change_type.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'append' => 'gray',
                        'prepend' => 'warning',
                        'replace_section' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('ai_prompt_override.field.description'))
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable(),

                ToggleColumn::make('enabled')
                    ->label(__('ai_prompt_override.field.enabled'))
                    ->sortable()
                    ->afterStateUpdated(function ($state, $record) {
                        $message = $state
                            ? __('ai_prompt_override.notification.enabled')
                            : __('ai_prompt_override.notification.disabled');

                        Notification::make()
                            ->success()
                            ->title($message)
                            ->send();
                    }),

                TextColumn::make('created_at')
                    ->label(__('ai_prompt_override.field.created_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('prompt_key')
                    ->label(__('ai_prompt_override.field.prompt_key'))
                    ->options(AIPromptOverride::availablePromptKeys()),

                SelectFilter::make('change_type')
                    ->label(__('ai_prompt_override.field.change_type'))
                    ->options([
                        'append' => __('ai_prompt_override.change_type.append'),
                        'prepend' => __('ai_prompt_override.change_type.prepend'),
                        'replace_section' => __('ai_prompt_override.change_type.replace_section'),
                    ]),

                TernaryFilter::make('enabled')
                    ->label(__('ai_prompt_override.field.enabled')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label(__('ai_prompt_override.action.enable_selected'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(function (Collection $records): void {
                            $records->each(fn ($record) => $record->update(['enabled' => true]));

                            Notification::make()
                                ->success()
                                ->title(__('ai_prompt_override.notification.enabled'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable')
                        ->label(__('ai_prompt_override.action.disable_selected'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->action(function (Collection $records): void {
                            $records->each(fn ($record) => $record->update(['enabled' => false]));

                            Notification::make()
                                ->success()
                                ->title(__('ai_prompt_override.notification.disabled'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('ai_prompt_override.empty.title'))
            ->emptyStateDescription(__('ai_prompt_override.empty.description'));
    }
}
