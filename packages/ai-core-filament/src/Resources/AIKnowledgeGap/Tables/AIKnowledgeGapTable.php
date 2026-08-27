<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeGap\Tables;

use Core45\AiCore\Filament\Resources\AIKnowledge\AIKnowledgeResource;
use Core45\AiCore\Models\AIKnowledgeGap;
use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Services\KnowledgeService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

class AIKnowledgeGapTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label(__('ai_knowledge.gap_field.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("ai_knowledge.gap_status.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'warning',
                        'resolved' => 'success',
                        'ignored' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('occurrence_count')
                    ->label(__('ai_knowledge.gap_field.occurrence_count'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 5 => 'danger',
                        $state >= 3 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('question')
                    ->label(__('ai_knowledge.gap_field.question'))
                    ->searchable()
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (AIKnowledgeGap $record): string => $record->question),

                TextColumn::make('audience')
                    ->label(__('ai_knowledge.field.audience'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("ai_knowledge.audience.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'public' => 'success',
                        'customer' => 'info',
                        'admin' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('confidence_score')
                    ->label(__('ai_knowledge.gap_field.confidence_score'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100).'%')
                    ->sortable()
                    ->color(fn ($state): string => match (true) {
                        (float) $state < 0.2 => 'danger',
                        (float) $state < 0.4 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('last_occurred_at')
                    ->label(__('ai_knowledge.gap_field.last_occurred_at'))
                    ->dateTime()
                    ->sortable()
                    ->since(),

                TextColumn::make('ai_response')
                    ->label(__('ai_knowledge.gap_field.ai_response'))
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resolver.name')
                    ->label(__('ai_knowledge.gap_field.resolved_by'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resolved_at')
                    ->label(__('ai_knowledge.gap_field.resolved_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('ai_knowledge.gap_field.status'))
                    ->options(AIKnowledgeGap::getStatusOptions())
                    ->default('open'),

                SelectFilter::make('audience')
                    ->label(__('ai_knowledge.field.audience'))
                    ->options(AIKnowledgeGap::getAudienceOptions()),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label(__('ai_knowledge.gap_action.resolve'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('success')
                    ->visible(fn (AIKnowledgeGap $record): bool => $record->isOpen())
                    ->schema([
                        TextInput::make('title')
                            ->label(__('ai_knowledge.field.title'))
                            ->required()
                            ->maxLength(255)
                            ->default(fn (AIKnowledgeGap $record): string => self::generateTitleFromQuestion($record->question)),

                        Textarea::make('content')
                            ->label(__('ai_knowledge.field.content'))
                            ->required()
                            ->rows(4)
                            ->helperText('Enter the answer to this question'),

                        Select::make('type')
                            ->label(__('ai_knowledge.field.type'))
                            ->options(AILearnedKnowledge::getTypeOptions())
                            ->default('faq')
                            ->required(),

                        Select::make('audience')
                            ->label(__('ai_knowledge.field.audience'))
                            ->options(AILearnedKnowledge::getAudienceOptions())
                            ->default(fn (AIKnowledgeGap $record): string => $record->audience)
                            ->required(),

                        Select::make('tier')
                            ->label(__('ai_knowledge.field.tier'))
                            ->options(AILearnedKnowledge::getTierOptions())
                            ->default('contextual')
                            ->required(),
                    ])
                    ->action(function (AIKnowledgeGap $record, array $data): void {
                        /** @var User $user */
                        $user = Auth::user();

                        // Create knowledge entry
                        $knowledge = app(KnowledgeService::class)->create([
                            'title' => $data['title'],
                            'content' => $data['content'],
                            'type' => $data['type'],
                            'audience' => $data['audience'],
                            'tier' => $data['tier'],
                            'source_type' => 'suggested',
                            'enabled' => true,
                        ]);

                        // Resolve the gap
                        $record->resolve($user, $knowledge);

                        Notification::make()
                            ->title('Knowledge Created')
                            ->body('The gap has been resolved with new knowledge.')
                            ->success()
                            ->send();
                    }),

                Action::make('ignore')
                    ->label(__('ai_knowledge.gap_action.ignore'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('gray')
                    ->visible(fn (AIKnowledgeGap $record): bool => $record->isOpen())
                    ->requiresConfirmation()
                    ->modalHeading('Ignore this gap?')
                    ->modalDescription('This question will be marked as ignored and won\'t appear in the open gaps list.')
                    ->action(function (AIKnowledgeGap $record): void {
                        /** @var User $user */
                        $user = Auth::user();
                        $record->ignore($user);

                        Notification::make()
                            ->title('Gap Ignored')
                            ->success()
                            ->send();
                    }),

                Action::make('view_knowledge')
                    ->label(__('ai_knowledge.gap_field.resolution_knowledge'))
                    ->icon(Heroicon::OutlinedLightBulb)
                    ->color('info')
                    ->visible(fn (AIKnowledgeGap $record): bool => $record->isResolved() && $record->resolution_knowledge_id !== null)
                    ->url(fn (AIKnowledgeGap $record): string => AIKnowledgeResource::getUrl('edit', ['record' => $record->resolution_knowledge_id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('occurrence_count', 'desc');
    }

    protected static function generateTitleFromQuestion(string $question): string
    {
        // Remove question marks and clean up
        $title = rtrim($question, '?');

        // Take first 60 characters
        if (strlen($title) > 60) {
            $lastSpace = strrpos(substr($title, 0, 60), ' ');
            if ($lastSpace !== false) {
                $title = substr($title, 0, $lastSpace);
            } else {
                $title = substr($title, 0, 60);
            }
        }

        return ucfirst($title);
    }
}
