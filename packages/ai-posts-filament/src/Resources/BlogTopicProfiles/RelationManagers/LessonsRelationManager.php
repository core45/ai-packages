<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\RelationManagers;

use Core45\AiPosts\Models\BlogProfileLesson;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $title = 'Profile Lessons';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lesson_type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('lesson_text')
                    ->label(__('Lesson'))
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('confidence')
                    ->label(__('Confidence'))
                    ->numeric(2)
                    ->sortable(),

                TextColumn::make('effectiveness_score')
                    ->label(__('Effectiveness'))
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('times_applied')
                    ->label(__('Applied'))
                    ->sortable(),

                ToggleColumn::make('active')
                    ->label(__('Active'))
                    ->sortable(),
            ])
            ->defaultSort('confidence', 'desc')
            ->actions([
                Action::make('details')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (BlogProfileLesson $record): string => $record->lesson_type.' - '.__('Lesson'))
                    ->modalContent(fn (BlogProfileLesson $record) => view('ai-posts::filament.blog-pipeline.lesson-details', [
                        'lesson' => $record,
                    ]))
                    ->modalSubmitAction(false),

                DeleteAction::make(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
