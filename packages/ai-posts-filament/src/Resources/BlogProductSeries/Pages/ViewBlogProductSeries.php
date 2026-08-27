<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\Pages;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Filament\Resources\BlogProductSeries\BlogProductSeriesResource;
use Core45\AiPosts\Jobs\BlogTopics\GenerateSeriesBatchJob;
use Core45\AiPosts\Jobs\BlogTopics\PlanProductSeriesJob;
use Core45\AiPosts\Models\BlogProductSeries;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewBlogProductSeries extends ViewRecord
{
    protected static string $resource = BlogProductSeriesResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->schema([
                        Section::make(__('Series Details'))
                            ->schema([
                                TextEntry::make('name')
                                    ->label(__('Name')),

                                TextEntry::make('profile.name')
                                    ->label(__('Profile')),

                                TextEntry::make('target_type')
                                    ->badge()
                                    ->label(__('Target Type')),

                                TextEntry::make('total_posts')
                                    ->label(__('Total Posts')),

                                TextEntry::make('posts_generated')
                                    ->label(__('Posts Generated')),

                                TextEntry::make('interval_days')
                                    ->label(__('Interval (days)')),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),

                        Section::make(__('Schedule'))
                            ->schema([
                                TextEntry::make('next_publish_at')
                                    ->dateTime()
                                    ->label(__('Next Publish')),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make()
                            ->schema([
                                TextEntry::make('id')
                                    ->label('ID')
                                    ->columnSpanFull(),
                                TextEntry::make('created_at')
                                    ->dateTime()
                                    ->label(__('Created at')),
                                TextEntry::make('updated_at')
                                    ->dateTime()
                                    ->label(__('Last modified at')),
                            ])
                            ->columns(2)
                            ->columnSpan(['lg' => 1]),

                        Section::make(__('Status'))
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->label(__('Status')),

                                IconEntry::make('auto_publish')
                                    ->boolean()
                                    ->label(__('Auto Publish')),
                            ])
                            ->columnSpan(['lg' => 1]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('planTopics')
                ->label(__('Plan Topics'))
                ->icon(Heroicon::OutlinedSparkles)
                ->requiresConfirmation()
                ->visible(fn (BlogProductSeries $record): bool => in_array($record->status, [
                    BlogProductSeriesStatus::Planning,
                ], true))
                ->action(function (): void {
                    /** @var BlogProductSeries $series */
                    $series = $this->record;

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    PlanProductSeriesJob::dispatch($series, $userId);

                    Notification::make()
                        ->title(__('Series planning queued'))
                        ->body(__('Topics will be generated in the background.'))
                        ->success()
                        ->send();
                }),

            Action::make('generateBatch')
                ->label(__('Generate Next Batch'))
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn (BlogProductSeries $record): bool => in_array($record->status, [
                    BlogProductSeriesStatus::Planned,
                    BlogProductSeriesStatus::InProgress,
                ], true))
                ->action(function (): void {
                    /** @var BlogProductSeries $series */
                    $series = $this->record;

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    GenerateSeriesBatchJob::dispatch($series, $userId);

                    Notification::make()
                        ->title(__('Batch generation queued'))
                        ->body(__('The next batch of posts will be generated in the background.'))
                        ->success()
                        ->send();
                }),

            Action::make('pause')
                ->label(__('Pause'))
                ->icon(Heroicon::OutlinedPause)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (BlogProductSeries $record): bool => $record->status === BlogProductSeriesStatus::InProgress)
                ->action(function (): void {
                    /** @var BlogProductSeries $series */
                    $series = $this->record;

                    $series->update(['status' => BlogProductSeriesStatus::Paused]);

                    Notification::make()
                        ->title(__('Series paused'))
                        ->success()
                        ->send();
                }),

            Action::make('resume')
                ->label(__('Resume'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (BlogProductSeries $record): bool => $record->status === BlogProductSeriesStatus::Paused)
                ->action(function (): void {
                    /** @var BlogProductSeries $series */
                    $series = $this->record;

                    $series->update(['status' => BlogProductSeriesStatus::InProgress]);

                    Notification::make()
                        ->title(__('Series resumed'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
