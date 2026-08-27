<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\RelationManagers;

use Core45\AiCore\Models\ImageDirectorProfile;
use Core45\AiCore\Models\ImageStyle;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Jobs\BlogTopics\GenerateBlogPostJob;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'candidates';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Series Candidates');
    }

    public function table(Table $table): Table
    {
        // A package cannot link to the host's own Post resource. The host
        // names it via `ai-posts.filament.post_resource`; when that is unset
        // or does not resolve to a real class the "View Post" action is
        // omitted entirely below rather than rendering a link to nowhere.
        // Same seam the BlogTopicBatches relation manager already uses.
        $postResourceClass = self::resolvePostResourceClass();

        return $table
            ->columns([
                TextColumn::make('rank')
                    ->sortable()
                    ->label(__('Rank')),

                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->label(__('Title')),

                TextColumn::make('intent')
                    ->limit(30)
                    ->label(__('Intent')),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->label(__('Status')),

                TextColumn::make('scheduled_publish_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Scheduled')),
            ])
            ->defaultSort('rank', 'asc')
            ->actions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->schema([
                        Section::make(__('Images'))
                            ->schema([
                                Toggle::make('featured_image')
                                    ->label(__('Generate Featured Image'))
                                    ->default(false)
                                    ->live(),

                                Toggle::make('gallery_enabled')
                                    ->label(__('Generate Gallery Images'))
                                    ->default(false)
                                    ->live(),

                                TextInput::make('gallery_count')
                                    ->label(__('Number of Gallery Images'))
                                    ->numeric()
                                    ->default(3)
                                    ->minValue(1)
                                    ->maxValue(8)
                                    ->visible(fn (Get $get): bool => (bool) $get('gallery_enabled')),

                                Toggle::make('in_text_enabled')
                                    ->label(__('Generate In-Text Images'))
                                    ->default(false)
                                    ->live(),

                                TextInput::make('in_text_count')
                                    ->label(__('Number of In-Text Images'))
                                    ->numeric()
                                    ->default(2)
                                    ->minValue(1)
                                    ->maxValue(5)
                                    ->visible(fn (Get $get): bool => (bool) $get('in_text_enabled')),

                                Select::make('image_director_profile_id')
                                    ->label(__('AI Image Profile'))
                                    ->options(fn (): array => ImageDirectorProfile::active()->orderBy('name')->pluck('name', 'id')->all()),
                                Select::make('image_style')
                                    ->label(__('Image style'))
                                    ->options(ImageStyle::options()),
                            ]),

                        Section::make(__('Content options'))
                            ->schema([
                                Toggle::make('generate_faq')
                                    ->label(__('Generate FAQ'))
                                    ->default(false)
                                    ->live(),

                                TextInput::make('faq_count')
                                    ->label(__('Number of FAQ Items'))
                                    ->numeric()
                                    ->default(5)
                                    ->minValue(3)
                                    ->maxValue(10)
                                    ->visible(fn (Get $get): bool => (bool) $get('generate_faq')),
                            ]),
                    ])
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Proposed)
                    ->action(function (BlogTopicCandidate $record, array $data): void {
                        $imageConfig = [
                            'featured_image' => (bool) ($data['featured_image'] ?? false),
                            'gallery' => [
                                'enabled' => (bool) ($data['gallery_enabled'] ?? false),
                                'count' => (int) ($data['gallery_count'] ?? 3),
                            ],
                            'in_text' => [
                                'enabled' => (bool) ($data['in_text_enabled'] ?? false),
                                'count' => (int) ($data['in_text_count'] ?? 2),
                            ],
                            'style' => $data['image_style'] ?? null,
                            'profile_id' => $data['image_director_profile_id'] ? (int) $data['image_director_profile_id'] : null,
                        ];

                        $hasAnyImages = $imageConfig['featured_image']
                            || $imageConfig['gallery']['enabled']
                            || $imageConfig['in_text']['enabled'];

                        $generateFaq = (bool) ($data['generate_faq'] ?? false);
                        $faqConfig = $generateFaq ? [
                            'enabled' => true,
                            'count' => (int) ($data['faq_count'] ?? 5),
                        ] : null;

                        $record->update([
                            'status' => BlogTopicCandidateStatus::Approved,
                            'approved_by' => Auth::id(),
                            'approved_at' => now(),
                            'generate_images' => $hasAnyImages,
                            'image_config' => $hasAnyImages ? $imageConfig : null,
                            'generate_faq' => $generateFaq,
                            'faq_config' => $faqConfig,
                        ]);

                        Notification::make()
                            ->title(__('Topic approved'))
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Proposed)
                    ->action(function (BlogTopicCandidate $record): void {
                        $record->update([
                            'status' => BlogTopicCandidateStatus::Rejected,
                        ]);

                        Notification::make()
                            ->title(__('Topic rejected'))
                            ->warning()
                            ->send();
                    }),

                Action::make('generatePost')
                    ->label(__('Generate Post'))
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Approved)
                    ->action(function (BlogTopicCandidate $record): void {
                        /** @var int|null $userId */
                        $userId = Auth::id();
                        GenerateBlogPostJob::dispatch($record, $userId);

                        Notification::make()
                            ->title(__('Blog post generation queued'))
                            ->body(__('The blog post will be generated in the background.'))
                            ->success()
                            ->send();
                    }),

                // Omitted entirely (rather than rendered as a dead link) when
                // the host has not configured `ai-posts.filament.post_resource`.
                $postResourceClass !== null
                    ? Action::make('viewPost')
                        ->label(__('View Post'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->visible(fn (BlogTopicCandidate $record): bool => $record->post_id !== null)
                        ->url(fn (BlogTopicCandidate $record): string => $postResourceClass::getUrl('edit', ['record' => $record->post_id]))
                    : null,

                Action::make('retry')
                    ->label(__('Retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Failed)
                    ->action(function (BlogTopicCandidate $record): void {
                        $record->update([
                            'status' => BlogTopicCandidateStatus::Generating,
                            'risk_flags_json' => null,
                        ]);

                        /** @var int|null $userId */
                        $userId = Auth::id();
                        GenerateBlogPostJob::dispatch($record, $userId);

                        Notification::make()
                            ->title(__('Topic retried'))
                            ->body(__('The blog post generation has been re-queued.'))
                            ->success()
                            ->send();
                    }),

                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BlogTopicCandidate $record): bool => in_array($record->status, [
                        BlogTopicCandidateStatus::Failed,
                        BlogTopicCandidateStatus::Rejected,
                    ], true))
                    ->action(function (BlogTopicCandidate $record): void {
                        $record->delete();

                        Notification::make()
                            ->title(__('Topic deleted'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * The host's Post resource class, or null when the host has not named one.
     *
     * @return class-string|null
     */
    private static function resolvePostResourceClass(): ?string
    {
        $resource = config('ai-posts.filament.post_resource');

        return is_string($resource) && $resource !== '' && class_exists($resource) ? $resource : null;
    }
}
