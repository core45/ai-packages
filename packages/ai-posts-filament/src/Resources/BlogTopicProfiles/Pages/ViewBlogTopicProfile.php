<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Pages;

use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Filament\Resources\BlogTopicBatches\BlogTopicBatchResource;
use Core45\AiPosts\Filament\Resources\BlogTopicProfiles\BlogTopicProfileResource;
use Core45\AiPosts\Jobs\BlogTopics\CreateTopicFromUrlsJob;
use Core45\AiPosts\Jobs\BlogTopics\GenerateSearchQueriesJob;
use Core45\AiPosts\Jobs\BlogTopics\RefreshSiteProfileJob;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogTopics\ManualTopicService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewBlogTopicProfile extends ViewRecord
{
    protected static string $resource = BlogTopicProfileResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->schema([
                        Section::make(__('Profile Details'))
                            ->schema([
                                TextEntry::make('name')
                                    ->label(__('Name'))
                                    ->columnSpanFull(),

                                TextEntry::make('seed_keywords')
                                    ->badge()
                                    ->label(__('Seed Keywords'))
                                    ->columnSpanFull(),

                                TextEntry::make('excluded_topics')
                                    ->badge()
                                    ->label(__('Excluded Topics'))
                                    ->columnSpanFull(),

                                TextEntry::make('constraints')
                                    ->label(__('Constraints'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),

                        Section::make(__('Profile Data'))
                            ->schema([
                                KeyValueEntry::make('profile_json')
                                    ->state(function (BlogTopicProfile $record): array {
                                        /** @var array<string, mixed> $data */
                                        $data = $record->getAttribute('profile_json') ?? [];

                                        $result = [];
                                        foreach ($data as $key => $value) {
                                            $result[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
                                        }

                                        return $result;
                                    })
                                    ->columnSpanFull(),
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
                                TextEntry::make('last_refreshed_at')
                                    ->label(__('Last Refreshed'))
                                    ->dateTime(),
                                TextEntry::make('created_at')
                                    ->label(__('Created at')),
                                TextEntry::make('updated_at')
                                    ->label(__('Last modified at')),
                            ])
                            ->columns(2)
                            ->columnSpan(['lg' => 1]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshProfile')
                ->label(__('Refresh Profile'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var BlogTopicProfile $profile */
                    $profile = $this->record;

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    RefreshSiteProfileJob::dispatch($profile, $userId);

                    Notification::make()
                        ->title(__('Profile refresh queued'))
                        ->body(__('The site profile will be refreshed in the background.'))
                        ->success()
                        ->send();
                }),

            Action::make('generateTopics')
                ->label(__('Generate Topics'))
                ->icon(Heroicon::OutlinedSparkles)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var BlogTopicProfile $profile */
                    $profile = $this->record;

                    $batch = BlogTopicBatch::create([
                        'profile_id' => $profile->id,
                        'status' => BlogTopicBatchStatus::Draft,
                        'config_json' => [
                            'seed_keywords' => $profile->seed_keywords,
                            'excluded_topics' => $profile->excluded_topics,
                        ],
                    ]);

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    GenerateSearchQueriesJob::dispatch($batch, $userId);

                    Notification::make()
                        ->title(__('Research pipeline started'))
                        ->body(__('A new research batch has been created and the pipeline is running.'))
                        ->success()
                        ->send();

                    $this->redirect(BlogTopicBatchResource::getUrl('view', ['record' => $batch]));
                }),

            Action::make('createTopicFromUrls')
                ->label(__('Create Topic from URLs'))
                ->icon(Heroicon::OutlinedLink)
                ->schema([
                    Textarea::make('urls')
                        ->label(__('Article URLs'))
                        ->helperText(__('Enter one URL per line. Content will be fetched and analyzed.'))
                        ->rows(4)
                        ->placeholder("https://example.com/article-1\nhttps://example.com/article-2"),
                    Textarea::make('pasted_text')
                        ->label(__('Pasted Text'))
                        ->helperText(__('Optionally paste article text directly if you cannot provide a URL.'))
                        ->rows(4),
                ])
                ->action(function (array $data, Action $action): void {
                    $urlsRaw = trim($data['urls'] ?? '');
                    $pastedText = trim($data['pasted_text'] ?? '');

                    if ($urlsRaw === '' && $pastedText === '') {
                        Notification::make()
                            ->title(__('Please provide at least one URL or paste some text.'))
                            ->danger()
                            ->send();

                        $action->halt();

                        return;
                    }

                    $urls = $urlsRaw !== ''
                        ? array_values(array_filter(array_map('trim', explode("\n", $urlsRaw))))
                        : [];

                    $invalidUrls = array_filter($urls, fn (string $u): bool => !filter_var($u, FILTER_VALIDATE_URL) || !in_array(parse_url($u, PHP_URL_SCHEME), ['http', 'https'], true));

                    if ($invalidUrls !== []) {
                        Notification::make()
                            ->title(__('Invalid URLs detected'))
                            ->body(__('Please ensure all lines are valid http/https URLs.'))
                            ->danger()
                            ->send();

                        $action->halt();

                        return;
                    }

                    /** @var BlogTopicProfile $profile */
                    $profile = $this->record;

                    /** @var int|null $userId */
                    $userId = Auth::id();

                    CreateTopicFromUrlsJob::dispatch(
                        $profile,
                        $urls,
                        $pastedText !== '' ? $pastedText : null,
                        $userId,
                    );

                    Notification::make()
                        ->title(__('Topic synthesis queued'))
                        ->body(__('The content will be fetched and analyzed in the background.'))
                        ->success()
                        ->send();
                }),

            Action::make('createManualTopic')
                ->label(__('Create Manual Topic'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(500),
                    TextInput::make('intent')
                        ->label(__('Intent'))
                        ->maxLength(255)
                        ->placeholder('e.g., informational, commercial'),
                    Textarea::make('rationale')
                        ->label(__('Rationale'))
                        ->required(),
                    Repeater::make('sources')
                        ->label(__('Sources'))
                        ->schema([
                            TextInput::make('url')
                                ->label(__('URL'))
                                ->url()
                                ->required(),
                            TextInput::make('title')
                                ->label(__('Title'))
                                ->maxLength(500),
                            Textarea::make('snippet')
                                ->label(__('Snippet'))
                                ->required(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->helperText(__('Add at least one source URL')),
                ])
                ->action(function (array $data): void {
                    /** @var BlogTopicProfile $profile */
                    $profile = $this->record;

                    $service = app(ManualTopicService::class);
                    $candidate = $service->createManualTopic(
                        profile: $profile,
                        title: $data['title'],
                        intent: $data['intent'] ?? 'informational',
                        rationale: $data['rationale'],
                        sources: $data['sources'],
                    );

                    Notification::make()
                        ->title(__('Manual topic created successfully'))
                        ->success()
                        ->send();

                    $this->redirect(BlogTopicBatchResource::getUrl('view', ['record' => $candidate->batch_id]));
                }),

            Action::make('edit')
                ->label(__('Edit'))
                ->url(fn (): string => BlogTopicProfileResource::getUrl('edit', ['record' => $this->record])),

            DeleteAction::make(),
        ];
    }
}
