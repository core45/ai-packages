<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Pages;

use BackedEnum;
use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Core45\AiPosts\Jobs\BlogPipeline\FeedbackAnalysisJob;
use Core45\AiPosts\Models\BlogPostFeedback;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Standalone AI-generation page for a single post's pipeline run.
 *
 * DECOUPLING NOTE (rewrite wave): this page used to extend
 * the host's `Filament\Resources\Posts\Pages\ManagePostPipeline` with
 * `protected static string $resource = PostResource::class;`, which made it a
 * sub-page of the host's `PostResource` (reachable via
 * `PostResource::getRecordSubNavigation()`). That coupling cannot survive the
 * package boundary, so this page is now standalone: it registers its own
 * route (`ai-generation/{record}`) and resolves the post directly through
 * `ModelRegistry::getModelClass('post')` instead of a resource's model.
 *
 * The rewrite wave MUST remove the `PostResource::getRecordSubNavigation()`
 * entry that pointed at the old class, and should link to this page's
 * `getUrl(['record' => $post->id])` from wherever that sub-navigation entry
 * used to appear (e.g. a header action on the post edit page).
 */
class ManagePostPipeline extends Page
{
    protected static ?string $slug = 'ai-generation';

    protected string $view = 'ai-posts::filament.blog-pipeline.post-pipeline-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static bool $shouldRegisterNavigation = false;

    public AIContentable&Model $post;

    public function mount(int|string $record): void
    {
        $postClass = app(ModelRegistry::class)->getModelClass('post');

        if ($postClass === null) {
            abort(404);
        }

        /** @var (AIContentable&Model)|null $post */
        $post = $postClass::query()->find($record);

        if ($post === null) {
            abort(404);
        }

        $this->post = $post;
    }

    /**
     * The route NAME stays plain (`pages.ai-generation`, from `$slug` above)
     * — `HasRoutes::getRelativeRouteName()` derives it from the slug, and a
     * `{record}` placeholder inside a route NAME (rather than its path) is
     * not something Laravel's `route()`/`routeIs()` handle. Only the route
     * PATH carries the parameter, via this override.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/ai-generation/{record}';
    }

    public static function getNavigationLabel(): string
    {
        return __('AI Generation');
    }

    public function getTitle(): string
    {
        return __('AI Generation');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $post = $this->post;

        $pipelineRun = BlogPostPipelineRun::query()
            ->where('post_id', $post->getKey())
            ->latest()
            ->first();

        $moduleOutputs = [];
        if ($pipelineRun !== null) {
            $outputs = $pipelineRun->module_outputs ?? [];
            foreach ($outputs as $slug => $output) {
                $moduleOutputs[] = [
                    'slug' => $slug,
                    'quality_score' => $output['quality_score'] ?? '-',
                ];
            }
        }

        $feedbacks = BlogPostFeedback::query()
            ->where('post_id', $post->getKey())
            ->with('user')
            ->latest('created_at')
            ->get();

        return [
            'post' => $post,
            'pipelineRun' => $pipelineRun,
            'moduleOutputs' => $moduleOutputs,
            'feedbacks' => $feedbacks,
        ];
    }

    protected function getHeaderActions(): array
    {
        $post = $this->post;

        $pipelineRun = BlogPostPipelineRun::query()
            ->where('post_id', $post->getKey())
            ->latest()
            ->first();

        if ($pipelineRun === null) {
            return [];
        }

        $moduleOptions = [];
        $outputs = $pipelineRun->module_outputs ?? [];
        foreach (array_keys($outputs) as $slug) {
            $moduleOptions[$slug] = $slug;
        }

        return [
            Action::make('viewPipelineRun')
                ->label(__('View Full Pipeline Run'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(BlogPostPipelineRunResource::getUrl('view', ['record' => $pipelineRun->id]))
                ->color('info'),

            Action::make('submitFeedback')
                ->label(__('Submit Feedback'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->schema([
                    Select::make('rating')
                        ->label(__('Rating'))
                        ->options([
                            1 => '1 - '.__('Poor'),
                            2 => '2 - '.__('Below Average'),
                            3 => '3 - '.__('Average'),
                            4 => '4 - '.__('Good'),
                            5 => '5 - '.__('Excellent'),
                        ])
                        ->required(),

                    Select::make('feedback_type')
                        ->label(__('Feedback Type'))
                        ->options([
                            'quality' => __('Quality'),
                            'accuracy' => __('Accuracy'),
                            'tone' => __('Tone'),
                            'structure' => __('Structure'),
                        ])
                        ->required(),

                    Select::make('module_slug')
                        ->label(__('Specific Module'))
                        ->options($moduleOptions)
                        ->nullable()
                        ->helperText(__('Leave empty for feedback on the whole post.')),

                    Textarea::make('comment')
                        ->label(__('Comment'))
                        ->required()
                        ->rows(4),

                    Select::make('action_requested')
                        ->label(__('Requested Action'))
                        ->options([
                            'none' => __('None'),
                            'regenerate_module' => __('Regenerate Module'),
                            'improve' => __('Improve'),
                        ])
                        ->default('none'),
                ])
                ->action(function (array $data) use ($pipelineRun, $post): void {
                    BlogPostFeedback::create([
                        'pipeline_run_id' => $pipelineRun->id,
                        'post_id' => $post->getKey(),
                        'user_id' => Auth::id(),
                        'module_slug' => $data['module_slug'],
                        'rating' => (int) $data['rating'],
                        'feedback_type' => $data['feedback_type'],
                        'comment' => $data['comment'],
                        'action_requested' => $data['action_requested'],
                    ]);

                    /** @var int|null $userId */
                    $userId = Auth::id();
                    FeedbackAnalysisJob::dispatch($pipelineRun, $userId);

                    // Honest about what the queued job can do: the feedback row
                    // and the dispatch are both real. FeedbackAnalysisJob does
                    // run a real LLM analysis, but this host has no
                    // BlogProfileLesson model/table to persist the extracted
                    // lessons into (see the job's docblock), so they only reach
                    // the log — nothing here yet feeds back into future
                    // generations. ->success() is kept because recording the
                    // feedback and queuing the analysis are both genuine.
                    Notification::make()
                        ->title(__('Feedback submitted'))
                        ->body(__('Your feedback has been recorded and analysis has been queued. Extracted lessons are logged for engineering review; this application has no profile store to apply them to automatically.'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
