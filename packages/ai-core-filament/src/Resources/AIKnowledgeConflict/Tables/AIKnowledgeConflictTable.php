<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Tables;

use Core45\AiCore\Enums\AIConflictStatus;
use Core45\AiCore\Enums\AIConflictType;
use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Core45\AiCore\Models\AIKnowledgeConflict;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

class AIKnowledgeConflictTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['knowledgeA', 'knowledgeB', 'resolver']))
            ->columns([
                TextColumn::make('status')
                    ->label(__('ai_knowledge.conflict_field.status'))
                    ->badge()
                    ->formatStateUsing(fn (AIConflictStatus $state): string => $state->label())
                    ->color(fn (AIConflictStatus $state): string => $state->color())
                    ->icon(fn (AIConflictStatus $state): string => $state->icon())
                    ->sortable(),

                TextColumn::make('conflict_type')
                    ->label(__('ai_knowledge.conflict_field.type'))
                    ->badge()
                    ->formatStateUsing(fn (AIConflictType $state): string => $state->label())
                    ->color(fn (AIConflictType $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('knowledgeA.title')
                    ->label(__('ai_knowledge.conflict_field.knowledge_a'))
                    ->limit(40)
                    ->searchable()
                    ->tooltip(fn (AIKnowledgeConflict $record): string => $record->knowledgeA->title)
                    ->url(fn (AIKnowledgeConflict $record): string => AIKnowledgeResource::getUrl('edit', ['record' => $record->knowledge_a_id])),

                TextColumn::make('knowledgeB.title')
                    ->label(__('ai_knowledge.conflict_field.knowledge_b'))
                    ->limit(40)
                    ->searchable()
                    ->tooltip(fn (AIKnowledgeConflict $record): string => $record->knowledgeB->title)
                    ->url(fn (AIKnowledgeConflict $record): string => AIKnowledgeResource::getUrl('edit', ['record' => $record->knowledge_b_id])),

                TextColumn::make('overlap_keywords')
                    ->label(__('ai_knowledge.conflict_field.overlap_keywords'))
                    ->badge()
                    ->color('gray')
                    ->separator(',')
                    ->limitList(3)
                    ->toggleable(),

                TextColumn::make('overlap_score')
                    ->label(__('ai_knowledge.conflict_field.overlap_score'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1, ',', ' ').'%')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('resolver.name')
                    ->label(__('ai_knowledge.conflict_field.resolved_by'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resolved_at')
                    ->label(__('ai_knowledge.conflict_field.resolved_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('ai_knowledge.conflict_field.status'))
                    ->options(AIConflictStatus::options())
                    ->default(AIConflictStatus::Detected->value),

                SelectFilter::make('conflict_type')
                    ->label(__('ai_knowledge.conflict_field.type'))
                    ->options(AIConflictType::options()),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label(__('ai_knowledge.conflict_action.resolve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (AIKnowledgeConflict $record): bool => $record->isUnresolved())
                    ->schema([
                        Textarea::make('resolution_notes')
                            ->label(__('ai_knowledge.conflict_field.resolution_notes'))
                            ->rows(3)
                            ->placeholder(__('ai_knowledge.conflict_placeholder.resolution_notes')),
                    ])
                    ->action(function (AIKnowledgeConflict $record, array $data): void {
                        /** @var User $user */
                        $user = Auth::user();
                        $record->resolve($user, $data['resolution_notes'] ?? '');

                        Notification::make()
                            ->title(__('ai_knowledge.conflict_notification.resolved'))
                            ->success()
                            ->send();
                    }),

                Action::make('dismiss')
                    ->label(__('ai_knowledge.conflict_action.dismiss'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (AIKnowledgeConflict $record): bool => $record->isUnresolved())
                    ->requiresConfirmation()
                    ->modalHeading(__('ai_knowledge.conflict_action.dismiss_heading'))
                    ->modalDescription(__('ai_knowledge.conflict_action.dismiss_description'))
                    ->action(function (AIKnowledgeConflict $record): void {
                        /** @var User $user */
                        $user = Auth::user();
                        $record->dismiss($user);

                        Notification::make()
                            ->title(__('ai_knowledge.conflict_notification.dismissed'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('dismiss_selected')
                        ->label(__('ai_knowledge.conflict_action.dismiss_selected'))
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            /** @var User $user */
                            $user = Auth::user();

                            foreach ($records as $record) {
                                /** @var AIKnowledgeConflict $record */
                                if ($record->isUnresolved()) {
                                    $record->dismiss($user);
                                }
                            }

                            Notification::make()
                                ->title(__('ai_knowledge.conflict_notification.bulk_dismissed'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('status', 'asc');
    }
}
