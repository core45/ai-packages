<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches\Pages;

use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\BlogTopicBatchResource;
use Core45\AiPosts\Jobs\BlogTopics\GenerateSearchQueriesJob;
use Core45\AiPosts\Models\BlogTopicBatch;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewBlogTopicBatch extends ViewRecord
{
    protected static string $resource = BlogTopicBatchResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                self::leftColumn(),
                self::rightColumn(),
            ]);
    }

    private static function leftColumn(): Group
    {
        return Group::make()
            ->schema([
                Section::make(__('Batch Details'))
                    ->schema([
                        TextEntry::make('profile.name')
                            ->label(__('Profile'))
                            ->columnSpanFull(),

                        TextEntry::make('status')
                            ->badge()
                            ->label(__('Status'))
                            ->columnSpanFull(),

                        TextEntry::make('error_message')
                            ->label(__('Error'))
                            ->visible(fn (BlogTopicBatch $record): bool => $record->error_message !== null)
                            ->columnSpanFull(),

                        TextEntry::make('started_at')
                            ->dateTime()
                            ->label(__('Started')),

                        TextEntry::make('finished_at')
                            ->dateTime()
                            ->label(__('Finished')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->columnSpan(['lg' => 2]);
    }

    private static function rightColumn(): Group
    {
        return Group::make()
            ->schema([
                Section::make()
                    ->schema([
                        TextEntry::make('id')
                            ->label(__('ID'))
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
            ])
            ->columnSpan(['lg' => 1]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label(__('Retry'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->visible(fn (BlogTopicBatch $record): bool => $record->status === BlogTopicBatchStatus::Failed)
                ->action(function (): void {
                    /** @var BlogTopicBatch $batch */
                    $batch = $this->record;

                    $batch->update([
                        'status' => BlogTopicBatchStatus::Draft,
                        'error_message' => null,
                        'started_at' => null,
                        'finished_at' => null,
                    ]);

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    GenerateSearchQueriesJob::dispatch($batch, $userId);

                    Notification::make()
                        ->title(__('Research pipeline restarted'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),

            Action::make('exportJson')
                ->label(__('Export JSON'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    /** @var BlogTopicBatch $batch */
                    $batch = $this->record;

                    $data = [
                        'batch' => $batch->toArray(),
                        'profile' => $batch->profile?->toArray(),
                        'sources' => $batch->sources()->get()->toArray(),
                        'candidates' => $batch->candidates()->get()->toArray(),
                        'llm_runs' => $batch->llmRuns()->get()->toArray(),
                    ];

                    $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $filename = "batch-{$batch->id}-export.json";

                    return response()->streamDownload(function () use ($json): void {
                        echo $json;
                    }, $filename, ['Content-Type' => 'application/json']);
                }),
        ];
    }
}
