<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicBatches\RelationManagers;

use Core45\AiCore\Models\ImageDirectorProfile;
use Core45\AiCore\Models\ImageStyle;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Jobs\BlogPipeline\StartPipelineJob;
use Core45\AiPosts\Jobs\BlogTopics\GenerateBlogPostJob;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
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

class TopicCandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'candidates';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Topic Candidates');
    }

    public function table(Table $table): Table
    {
        $aiImagesDisabled = static fn (): bool => !(bool) config('ai.assistant.blog_topics.images.enabled', false);
        $aiImagesDisabledNote = static fn (): ?string => (bool) config('ai.assistant.blog_topics.images.enabled', false)
            ? null
            : (string) __('AI image generation is disabled in the configuration.');

        // A package cannot link to the host's own Post resource. The host
        // names it via `ai-posts.filament.post_resource`; when it is unset or
        // does not resolve to a real class, the "View Post" action is simply
        // omitted below rather than rendering a link to nowhere.
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

                TextColumn::make('approvedBy.name')
                    ->label(__('Approved By'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('rank', 'asc')
            ->actions(array_values(array_filter([
                Action::make('details')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (BlogTopicCandidate $record): string => $record->title)
                    ->fillForm(fn (BlogTopicCandidate $record): array => [
                        'title' => $record->title,
                        'slug' => $record->slug,
                        'intent' => $record->intent,
                        'rationale' => $record->rationale,
                        'outline_json' => array_map(fn (array $section): array => [
                            'heading' => $section['heading'] ?? '',
                            'points_text' => implode("\n", $section['points'] ?? []),
                        ], $record->outline_json ?? []),
                    ])
                    ->schema([
                        TextInput::make('title')
                            ->label(__('Title'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('intent')
                            ->label(__('Intent'))
                            ->maxLength(255),

                        Textarea::make('rationale')
                            ->label(__('Rationale'))
                            ->rows(3),

                        Repeater::make('outline_json')
                            ->label(__('Outline'))
                            ->schema([
                                TextInput::make('heading')
                                    ->label(__('Heading'))
                                    ->required(),

                                Textarea::make('points_text')
                                    ->label(__('Points'))
                                    ->helperText(__('One point per line'))
                                    ->rows(3),
                            ])
                            ->addActionLabel(__('Add Section'))
                            ->reorderable()
                            ->collapsible(),
                    ])
                    ->modalContent(fn (BlogTopicCandidate $record) => view('ai-posts::filament.blog-topics.candidate-details', [
                        'candidate' => $record,
                    ]))
                    ->modalSubmitActionLabel(__('Save'))
                    ->action(function (BlogTopicCandidate $record, array $data): void {
                        /** @var array<int, array{heading: string, points_text?: string}> $outlineSections */
                        $outlineSections = $data['outline_json'] ?? [];
                        $outline = array_map(function (array $section): array {
                            $pointsText = trim((string) ($section['points_text'] ?? ''));

                            return [
                                'heading' => $section['heading'],
                                'points' => $pointsText !== '' ? array_map('trim', explode("\n", $pointsText)) : [],
                            ];
                        }, $outlineSections);

                        $record->update([
                            'title' => $data['title'],
                            'slug' => $data['slug'],
                            'intent' => $data['intent'],
                            'rationale' => $data['rationale'],
                            'outline_json' => $outline,
                        ]);

                        Notification::make()
                            ->title(__('Topic updated'))
                            ->success()
                            ->send();
                    }),

                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->schema([
                        Section::make(__('Images'))
                            ->description($aiImagesDisabledNote)
                            ->schema([
                                Toggle::make('featured_image')
                                    ->label(__('Generate Featured Image'))
                                    ->default(false)
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                Toggle::make('gallery_enabled')
                                    ->label(__('Generate Gallery Images'))
                                    ->default(false)
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                TextInput::make('gallery_count')
                                    ->label(__('Number of Gallery Images'))
                                    ->numeric()
                                    ->default(3)
                                    ->minValue(1)
                                    ->maxValue(8)
                                    ->disabled($aiImagesDisabled)
                                    ->visible(fn (Get $get): bool => (bool) $get('gallery_enabled')),

                                Toggle::make('in_text_enabled')
                                    ->label(__('Generate In-Text Images'))
                                    ->default(false)
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                TextInput::make('in_text_count')
                                    ->label(__('Number of In-Text Images'))
                                    ->numeric()
                                    ->default(2)
                                    ->minValue(1)
                                    ->maxValue(5)
                                    ->disabled($aiImagesDisabled)
                                    ->visible(fn (Get $get): bool => (bool) $get('in_text_enabled')),

                                Select::make('image_director_profile_id')
                                    ->label(__('AI Image Profile'))
                                    ->disabled($aiImagesDisabled)
                                    ->options(fn (): array => ImageDirectorProfile::active()->orderBy('name')->pluck('name', 'id')->all()),
                                Select::make('image_style')
                                    ->label(__('Image style'))
                                    ->disabled($aiImagesDisabled)
                                    ->options(ImageStyle::options()),
                            ]),

                        Section::make(__('Content options'))
                            ->schema([
                                Toggle::make('humanize_content')
                                    ->label(__('Humanize Content'))
                                    ->helperText(__('Rewrite AI-generated text to appear more human-written'))
                                    ->default(false)
                                    ->visible(fn (): bool => (bool) config('ai.assistant.ai_humanization.enabled', false)),

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

                                Toggle::make('enable_citations')
                                    ->label(__('Enable Citations'))
                                    ->helperText(__('Include source references [1], [2] in generated content'))
                                    ->default(false),
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
                            'humanize_content' => (bool) ($data['humanize_content'] ?? false),
                            'generate_faq' => $generateFaq,
                            'faq_config' => $faqConfig,
                            'enable_citations' => (bool) ($data['enable_citations'] ?? false),
                        ]);

                        Notification::make()
                            ->title(__('Topic approved'))
                            ->success()
                            ->send();
                    }),

                Action::make('editSettings')
                    ->label(__('Edit Settings'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->color('warning')
                    ->schema([
                        Section::make(__('Images'))
                            ->description($aiImagesDisabledNote)
                            ->schema([
                                Toggle::make('featured_image')
                                    ->label(__('Generate Featured Image'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) ($record->image_config['featured_image'] ?? false))
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                Toggle::make('gallery_enabled')
                                    ->label(__('Generate Gallery Images'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) ($record->image_config['gallery']['enabled'] ?? false))
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                TextInput::make('gallery_count')
                                    ->label(__('Number of Gallery Images'))
                                    ->numeric()
                                    ->default(fn (BlogTopicCandidate $record): int => (int) ($record->image_config['gallery']['count'] ?? 3))
                                    ->minValue(1)
                                    ->maxValue(8)
                                    ->disabled($aiImagesDisabled)
                                    ->visible(fn (Get $get): bool => (bool) $get('gallery_enabled')),

                                Toggle::make('in_text_enabled')
                                    ->label(__('Generate In-Text Images'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) ($record->image_config['in_text']['enabled'] ?? false))
                                    ->disabled($aiImagesDisabled)
                                    ->live(),

                                TextInput::make('in_text_count')
                                    ->label(__('Number of In-Text Images'))
                                    ->numeric()
                                    ->default(fn (BlogTopicCandidate $record): int => (int) ($record->image_config['in_text']['count'] ?? 2))
                                    ->minValue(1)
                                    ->maxValue(5)
                                    ->disabled($aiImagesDisabled)
                                    ->visible(fn (Get $get): bool => (bool) $get('in_text_enabled')),

                                Select::make('image_director_profile_id')
                                    ->label(__('AI Image Profile'))
                                    ->disabled($aiImagesDisabled)
                                    ->options(fn (): array => ImageDirectorProfile::active()->orderBy('name')->pluck('name', 'id')->all())
                                    ->default(fn (BlogTopicCandidate $record): ?int => isset($record->image_config['profile_id']) ? (int) $record->image_config['profile_id'] : null),
                                Select::make('image_style')
                                    ->label(__('Image style'))
                                    ->disabled($aiImagesDisabled)
                                    ->options(ImageStyle::options())
                                    ->default(fn (BlogTopicCandidate $record): ?string => $record->image_config['style'] ?? null),
                            ]),

                        Section::make(__('Content options'))
                            ->schema([
                                Toggle::make('humanize_content')
                                    ->label(__('Humanize Content'))
                                    ->helperText(__('Rewrite AI-generated text to appear more human-written'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) $record->humanize_content)
                                    ->visible(fn (): bool => (bool) config('ai.assistant.ai_humanization.enabled', false)),

                                Toggle::make('generate_faq')
                                    ->label(__('Generate FAQ'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) $record->generate_faq)
                                    ->live(),

                                TextInput::make('faq_count')
                                    ->label(__('Number of FAQ Items'))
                                    ->numeric()
                                    ->default(fn (BlogTopicCandidate $record): int => (int) ($record->faq_config['count'] ?? 5))
                                    ->minValue(3)
                                    ->maxValue(10)
                                    ->visible(fn (Get $get): bool => (bool) $get('generate_faq')),

                                Toggle::make('enable_citations')
                                    ->label(__('Enable Citations'))
                                    ->helperText(__('Include source references [1], [2] in generated content'))
                                    ->default(fn (BlogTopicCandidate $record): bool => (bool) $record->enable_citations),
                            ]),
                    ])
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Approved)
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
                            'generate_images' => $hasAnyImages,
                            'image_config' => $hasAnyImages ? $imageConfig : null,
                            'humanize_content' => (bool) ($data['humanize_content'] ?? false),
                            'generate_faq' => $generateFaq,
                            'faq_config' => $faqConfig,
                            'enable_citations' => (bool) ($data['enable_citations'] ?? false),
                        ]);

                        Notification::make()
                            ->title(__('Settings updated'))
                            ->success()
                            ->send();
                    }),

                Action::make('unapprove')
                    ->label(__('Unapprove'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('This will revert the topic back to Proposed status.'))
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Approved)
                    ->action(function (BlogTopicCandidate $record): void {
                        $record->update([
                            'status' => BlogTopicCandidateStatus::Proposed,
                            'approved_by' => null,
                            'approved_at' => null,
                            'generate_images' => false,
                            'image_config' => null,
                            'humanize_content' => false,
                            'generate_faq' => false,
                            'faq_config' => null,
                            'enable_citations' => false,
                        ]);

                        Notification::make()
                            ->title(__('Topic unapproved'))
                            ->warning()
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

                Action::make('generatePipeline')
                    ->label(fn (BlogTopicCandidate $record): string => $record->pipeline_run_id === null
                        ? __('Generate')
                        : __('Restart'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Generate Blog Post'))
                    ->modalDescription(function (BlogTopicCandidate $record): string {
                        if ($record->pipeline_run_id === null) {
                            return __('This will start the content pipeline. The post will be generated using configurable modules with quality checks.');
                        }

                        $run = $record->pipelineRun;

                        return __('Discards the current run (status: :status, last activity :ago) and starts the pipeline fresh.', [
                            'status' => $run->status->getLabel(),
                            'ago' => $run->updated_at?->diffForHumans() ?? '—',
                        ]);
                    })
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->status === BlogTopicCandidateStatus::Approved
                        && ($record->pipeline_run_id === null || $record->pipelineIsRestartable()))
                    ->action(function (BlogTopicCandidate $record): void {
                        $profile = $record->batch->profile;
                        if ($profile === null) {
                            Notification::make()
                                ->title(__('No profile found'))
                                ->body(__('The batch does not have a topic profile assigned.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($record->pipeline_run_id !== null) {
                            $record->pipelineRun?->delete();
                            $record->update(['pipeline_run_id' => null]);
                        }

                        /** @var int|null $userId */
                        $userId = Auth::id();
                        StartPipelineJob::dispatch($record, $profile, $userId);

                        Notification::make()
                            ->title(__('Pipeline started'))
                            ->body(__('The multi-module blog pipeline has been queued for ":title".', ['title' => $record->title]))
                            ->success()
                            ->send();
                    }),

                Action::make('pipelineStatus')
                    ->label(__('Pipeline status'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->color('gray')
                    ->visible(fn (BlogTopicCandidate $record): bool => $record->pipeline_run_id !== null)
                    ->modalHeading(__('Pipeline status'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(function (BlogTopicCandidate $record): array {
                        $run = $record->pipelineRun;

                        return [
                            TextEntry::make('run_status')
                                ->label(__('Status'))
                                ->state($run->status->getLabel()),
                            TextEntry::make('current_step')
                                ->label(__('Current step'))
                                ->state($run->current_step ?? '—'),
                            TextEntry::make('started')
                                ->label(__('Started'))
                                ->state($run->started_at?->diffForHumans() ?? '—'),
                            TextEntry::make('last_activity')
                                ->label(__('Last activity'))
                                ->state($run->updated_at?->diffForHumans() ?? '—'),
                            TextEntry::make('health')
                                ->label(__('Health'))
                                ->state($this->pipelineHealthNote($record)),
                        ];
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
            ])));
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Human-readable health line for the read-only pipeline status modal.
     */
    private function pipelineHealthNote(BlogTopicCandidate $record): string
    {
        $run = $record->pipelineRun;

        if ($run === null) {
            return __('No pipeline run.');
        }

        if ($run->status === BlogPostPipelineStatus::Failed) {
            $step = $run->current_step;

            return $step !== null
                ? __('Failed at step: :step.', ['step' => $step])
                : __('Failed.');
        }

        // Artifact created but the run has not reached a terminal state: the
        // downstream (enrichment/translation) phases are still working on the
        // Post. Deliberately NOT restartable — a restart would orphan the Post.
        if ($record->post_id !== null || $run->post_id !== null) {
            return __('Post created and still processing — open it to see progress.');
        }

        if ($record->pipelineIsRestartable()) {
            return __('Appears stuck — no recent activity and no post yet. Use Restart.');
        }

        return __('Running normally.');
    }

    /**
     * The host's own Post Filament resource, named through config since a
     * package cannot import a host-owned class. Returns null (rather than a
     * bad class name) when unset or when the configured class does not exist,
     * so callers can drop the cross-link cleanly.
     */
    private static function resolvePostResourceClass(): ?string
    {
        $resource = config('ai-posts.filament.post_resource');

        return is_string($resource) && $resource !== '' && class_exists($resource) ? $resource : null;
    }
}
