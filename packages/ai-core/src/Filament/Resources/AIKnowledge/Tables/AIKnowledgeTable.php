<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge\Tables;

use Core45\AiCore\Enums\AIKnowledgeType;
use Core45\AiCore\Models\AILearnedKnowledge;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AIKnowledgeTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->searchable()->sortable(),
                IconColumn::make('enabled')
                    ->label(__('ai_knowledge.field.enabled'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('ai_knowledge.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (AIKnowledgeType $state): string => $state->label())
                    ->color(fn (AIKnowledgeType $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('tier')
                    ->label(__('ai_knowledge.field.tier'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("ai_knowledge.tier.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'contextual' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

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

                TextColumn::make('title')
                    ->label(__('ai_knowledge.field.title'))
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('content')
                    ->label(__('ai_knowledge.field.content'))
                    ->searchable()
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('use_count')
                    ->label(__('ai_knowledge.field.use_count'))
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('last_used_at')
                    ->label(__('ai_knowledge.field.last_used_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.name')
                    ->label(__('ai_knowledge.field.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('ai_knowledge.field.type'))
                    ->options(AILearnedKnowledge::getTypeOptions()),

                SelectFilter::make('tier')
                    ->label(__('ai_knowledge.field.tier'))
                    ->options(AILearnedKnowledge::getTierOptions()),

                SelectFilter::make('audience')
                    ->label(__('ai_knowledge.field.audience'))
                    ->options(AILearnedKnowledge::getAudienceOptions()),

                SelectFilter::make('enabled')
                    ->label(__('ai_knowledge.field.enabled'))
                    ->options([
                        '1' => __('Yes'),
                        '0' => __('No'),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
