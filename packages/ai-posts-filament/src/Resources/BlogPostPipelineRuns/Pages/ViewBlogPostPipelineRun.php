<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\Pages;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ViewBlogPostPipelineRun extends ViewRecord
{
    protected static string $resource = BlogPostPipelineRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('PipelineRunTabs')
                ->columnSpanFull()
                ->tabs([
                    self::overviewTab(),
                    self::researchDataTab(),
                    self::moduleOutputsTab(),
                    self::qualityLogTab(),
                    self::configTab(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resume')
                ->label(__('Resume'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (BlogPostPipelineRun $record): bool => $record->status === BlogPostPipelineStatus::Paused)
                ->action(function (BlogPostPipelineRun $record): void {
                    try {
                        app(PipelineOrchestratorService::class)->resume($record);

                        // Honest about the gap: resume() really does un-pause
                        // the run and point current_step at the next module
                        // (see PipelineOrchestratorService::resume()), but this
                        // host ships no module-execution/assembly job to pick
                        // it up from there, so the run will not advance on its
                        // own. ->warning() reflects that the state change is
                        // real but incomplete. Restore ->success() once the
                        // executor jobs are ported.
                        Notification::make()
                            ->title(__('Pipeline un-paused'))
                            ->body(__('The run has moved past its paused step, but this application has no module-execution job installed, so it will not advance further on its own.'))
                            ->warning()
                            ->send();
                    } catch (\LogicException $e) {
                        Notification::make()
                            ->title(__('Cannot resume pipeline'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (BlogPostPipelineRun $record): bool => !$record->status->isTerminal())
                ->action(function (BlogPostPipelineRun $record): void {
                    app(PipelineOrchestratorService::class)->cancel($record);

                    Notification::make()
                        ->title(__('Pipeline cancelled'))
                        ->warning()
                        ->send();
                }),

            Action::make('forceComplete')
                ->label(__('Force Complete'))
                ->icon(Heroicon::OutlinedBolt)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('This will mark the run for forced assembly, but this application has no assembly job installed, so the post will not actually be assembled.'))
                ->visible(fn (BlogPostPipelineRun $record): bool => !$record->status->isTerminal() && $record->status !== BlogPostPipelineStatus::Completed)
                ->action(function (BlogPostPipelineRun $record): void {
                    app(PipelineOrchestratorService::class)->forceComplete($record);

                    // Honest about the gap: forceComplete() really does write
                    // the `assembly:forced` step marker (visible on this page
                    // and in the runs table), but there is no
                    // AssemblePostJob in this host to dispatch — see
                    // PipelineOrchestratorService::forceComplete(). ->warning()
                    // reflects that nothing will actually assemble the post.
                    // Restore ->success() and this copy once an assembly job
                    // ships.
                    Notification::make()
                        ->title(__('Run marked for forced assembly'))
                        ->body(__('The step has been updated, but no assembly job is installed in this application, so the post will not be assembled.'))
                        ->warning()
                        ->send();
                }),
        ];
    }

    private static function overviewTab(): Tab
    {
        return Tab::make(__('Overview'))
            ->schema([
                Section::make(__('Pipeline Status'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),

                        TextEntry::make('current_step')
                            ->label(__('Current Step')),

                        TextEntry::make('primary_locale')
                            ->label(__('Primary Locale')),

                        TextEntry::make('elapsed_time')
                            ->label(__('Elapsed Time'))
                            ->state(function (BlogPostPipelineRun $record): string {
                                if ($record->started_at === null) {
                                    return '-';
                                }

                                $startedAt = Carbon::parse($record->started_at);
                                $end = $record->completed_at !== null ? Carbon::parse($record->completed_at) : now();

                                return $startedAt->diff($end)->forHumans();
                            }),

                        TextEntry::make('total_cost')
                            ->label(__('Total Cost'))
                            ->state(fn (BlogPostPipelineRun $record): string => number_format((float) $record->total_cost, 4, ',', ' ').' EUR'),

                        TextEntry::make('candidate.title')
                            ->label(__('Candidate Title')),

                        TextEntry::make('profile.name')
                            ->label(__('Profile')),

                        TextEntry::make('post_link')
                            ->label(__('Generated Post'))
                            ->state(function (BlogPostPipelineRun $record): string {
                                if ($record->post_id === null) {
                                    return __('Not yet created');
                                }

                                return __('Post #:id', ['id' => $record->post_id]);
                            })
                            ->url(function (BlogPostPipelineRun $record): ?string {
                                if ($record->post_id === null) {
                                    return null;
                                }

                                // Resolved via Filament's model-to-resource lookup
                                // instead of importing the host's `PostResource`
                                // FQCN — this package holds no reference to a
                                // host namespace.
                                $postClass = app(ModelRegistry::class)->getModelClass('post');
                                $resourceClass = $postClass !== null ? Filament::getModelResource($postClass) : null;

                                if ($resourceClass === null) {
                                    return null;
                                }

                                return $resourceClass::getUrl('edit', ['record' => $record->post_id]);
                            }),

                        TextEntry::make('started_at')
                            ->label(__('Started'))
                            ->dateTime(),

                        TextEntry::make('completed_at')
                            ->label(__('Completed'))
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }

    private static function researchDataTab(): Tab
    {
        return Tab::make(__('Research Data'))
            ->schema([
                Section::make(__('Research Results'))
                    ->schema([
                        KeyValueEntry::make('research_data')
                            ->hiddenLabel()
                            ->state(function (BlogPostPipelineRun $record): array {
                                $data = $record->research_data ?? [];

                                if ($data === []) {
                                    return [__('No data') => __('No research data collected yet.')];
                                }

                                $flat = [];
                                foreach ($data as $key => $value) {
                                    if (is_array($value)) {
                                        $flat[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
                                    } else {
                                        $flat[$key] = (string) $value;
                                    }
                                }

                                return $flat;
                            })
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->visible(fn (BlogPostPipelineRun $record): bool => !empty($record->research_data));
    }

    private static function moduleOutputsTab(): Tab
    {
        return Tab::make(__('Module Outputs'))
            ->schema([
                Section::make(__('Module Results'))
                    ->schema([
                        RepeatableEntry::make('module_outputs_list')
                            ->hiddenLabel()
                            ->state(function (BlogPostPipelineRun $record): array {
                                $outputs = $record->module_outputs ?? [];

                                $list = [];
                                foreach ($outputs as $slug => $output) {
                                    $list[] = [
                                        'module_slug' => $slug,
                                        'quality_score' => $output['quality_score'] ?? '-',
                                        'llm_used' => $output['llm_used'] ?? '-',
                                        'attempts' => $output['attempts'] ?? 1,
                                        'content_preview' => mb_substr((string) ($output['content'] ?? ''), 0, 300).'...',
                                    ];
                                }

                                return $list;
                            })
                            ->schema([
                                TextEntry::make('module_slug')
                                    ->label(__('Module'))
                                    ->badge()
                                    ->color('info'),

                                TextEntry::make('quality_score')
                                    ->label(__('Quality Score')),

                                TextEntry::make('llm_used')
                                    ->label(__('LLM Used')),

                                TextEntry::make('attempts')
                                    ->label(__('Attempts')),

                                TextEntry::make('content_preview')
                                    ->label(__('Content Preview'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->visible(fn (BlogPostPipelineRun $record): bool => !empty($record->module_outputs));
    }

    private static function qualityLogTab(): Tab
    {
        return Tab::make(__('Quality Log'))
            ->schema([
                Section::make(__('Quality Evaluations'))
                    ->schema([
                        RepeatableEntry::make('quality_log')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('module_slug')
                                    ->label(__('Module'))
                                    ->badge(),

                                TextEntry::make('overall_score')
                                    ->label(__('Score')),

                                TextEntry::make('decision')
                                    ->label(__('Decision'))
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'pass' => 'success',
                                        'retry_same' => 'warning',
                                        'escalate' => 'info',
                                        'pause' => 'danger',
                                        default => 'gray',
                                    }),

                                TextEntry::make('quality_gate_bypassed')
                                    ->label(__('Bypassed'))
                                    ->badge()
                                    ->color('danger')
                                    ->state(fn (array $state): ?string => ($state['quality_gate_bypassed'] ?? false) ? (string) __('BYPASSED') : null)
                                    ->visible(fn (array $state): bool => $state['quality_gate_bypassed'] ?? false),

                                TextEntry::make('bypass_reason')
                                    ->label(__('Bypass Reason'))
                                    ->state(fn (array $state): string => (string) ($state['bypass_reason'] ?? ''))
                                    ->visible(fn (array $state): bool => $state['quality_gate_bypassed'] ?? false)
                                    ->columnSpanFull(),

                                TextEntry::make('issues')
                                    ->label(__('Issues'))
                                    ->state(fn (array $state): string => implode('; ', $state['issues'] ?? []))
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->visible(fn (BlogPostPipelineRun $record): bool => !empty($record->quality_log));
    }

    private static function configTab(): Tab
    {
        return Tab::make(__('Config'))
            ->schema([
                Section::make(__('Configuration Snapshot'))
                    ->schema([
                        TextEntry::make('config_snapshot_json')
                            ->hiddenLabel()
                            ->state(fn (BlogPostPipelineRun $record): string => json_encode($record->config_snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                            ->extraAttributes(['style' => 'font-family: monospace; white-space: pre-wrap; max-height: 500px; overflow-y: auto;'])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
