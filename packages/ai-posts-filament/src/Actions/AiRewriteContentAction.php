<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Actions;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Enums\AIMediaTarget;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Models\AIContentBatch;
use Core45\AiCore\Models\ImageDirectorProfile;
use Core45\AiCore\Models\ImageStyle;
use Core45\AiCore\Services\ImageGeneration\FalAiService;
use Core45\AiPosts\Jobs\ProcessPostAIContent;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Bulk "AI Rewrite Content" action: creates an {@see AIContentBatch} and
 * dispatches {@see ProcessPostAIContent} per selected post, optionally
 * asking for AI image-generation options first.
 *
 * Lives in ai-posts because it is driven by ai-posts' own job.
 */
class AiRewriteContentAction
{
    public static function makeBulkAction(?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'ai_rewrite_content')
            ->label(__('AI Rewrite Content'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->visible(fn (): bool => (bool) config('ai.assistant.enabled', false))
            ->requiresConfirmation()
            ->modalHeading(__('AI Content Rewrite'))
            ->modalDescription(__('This will rewrite and translate content for selected posts using AI. The process runs in the background and you will receive a notification when complete.'))
            ->modalSubmitActionLabel(__('Start AI Rewrite'))
            ->schema(fn (): array => self::imageGenerationSchema())
            ->action(function (Collection $records, array $data) {
                /** @var int $userId */
                $userId = Auth::id();
                $mediaOptions = self::buildMediaOptions($data);

                $postClass = app(ModelRegistry::class)->getModelClassOrFail('post');

                $batch = AIContentBatch::createForModel(
                    $postClass,
                    $records->pluck('id')->toArray(),
                    $userId
                );

                if (!empty($mediaOptions)) {
                    $batch->update(['media_options' => $mediaOptions]);
                }

                /** @var AIContentable&Model $post */
                foreach ($records as $post) {
                    ProcessPostAIContent::dispatch(
                        $post,
                        $batch,
                        $userId,
                        $mediaOptions
                    );
                }

                $body = __(':count posts queued for AI content rewrite. You will receive a notification when complete.', [
                    'count' => $records->count(),
                ]);

                if (!empty($mediaOptions)) {
                    $body .= ' '.__('AI images will also be generated.');
                }

                Notification::make()
                    ->title(__('AI Content Rewrite Started'))
                    ->body($body)
                    ->info()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Extract media generation options from form data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function buildMediaOptions(array $data): array
    {
        if (empty($data['ai_images_enabled'])) {
            return [];
        }

        return [
            'enabled' => true,
            'model' => $data['ai_image_model'] ?? app(GeneratesImages::class)::defaultImageModel(),
            'target' => $data['ai_image_target'] ?? 'gallery',
            'gallery_count' => (int) ($data['ai_gallery_count'] ?? 3),
            'aspect_ratio' => $data['ai_aspect_ratio'] ?? '1:1',
            'image_style' => $data['image_style'] ?? null,
            'image_director_profile_id' => $data['image_director_profile_id'] ?? null,
        ];
    }

    /**
     * Build the AI image generation form section for the bulk action.
     *
     * Returns an empty schema when the active provider has no credentials,
     * so the action degrades to a plain confirmation.
     *
     * @return array<Component>
     */
    private static function imageGenerationSchema(): array
    {
        $provider = app(GeneratesImages::class);

        if (!$provider::isConfigured()) {
            return [];
        }

        $providerLabel = $provider->providerName() === 'gemini' ? 'Gemini' : 'fal.ai';

        return [
            Section::make(__('AI Image Generation'))
                ->description(__('Optionally generate AI images for selected posts'))
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    Toggle::make('ai_images_enabled')
                        ->label(__('Generate AI Images'))
                        ->helperText(__("Uses {$providerLabel} to generate blog post images"))
                        ->live()
                        ->default(false),
                    Select::make('ai_image_model')
                        ->label(__('Image Model'))
                        ->options($provider::getImageModelOptions())
                        ->default($provider::defaultImageModel())
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                    Select::make('ai_image_target')
                        ->label(__('Image Target'))
                        ->options(AIMediaTarget::options())
                        ->default(AIMediaTarget::Gallery->value)
                        ->native(false)
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                    TextInput::make('ai_gallery_count')
                        ->label(__('Gallery Images Per Post'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue((int) config('fal-ai.gallery.max_count', 8))
                        ->default((int) config('fal-ai.gallery.default_count', 3))
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')
                            && in_array($get('ai_image_target'), ['gallery', 'both'], true)),
                    Select::make('ai_aspect_ratio')
                        ->label(__('Aspect Ratio'))
                        ->options($provider::getAspectRatioOptions())
                        ->default('1:1')
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                    Select::make('image_director_profile_id')
                        ->label(__('AI Image Profile'))
                        ->options(fn (): array => ImageDirectorProfile::active()->orderBy('name')->pluck('name', 'id')->all())
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                    Select::make('image_style')
                        ->label(__('Image style'))
                        ->options(ImageStyle::options())
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                    TextEntry::make('ai_cost_estimate')
                        ->label(__('Estimated Cost'))
                        ->state(function (Get $get): string {
                            if (!$get('ai_images_enabled')) {
                                return __('No image generation selected');
                            }

                            if (!app(GeneratesImages::class)::supportsCostEstimate()) {
                                return __('Cost estimation not available for this provider');
                            }

                            // Only fal.ai reports a per-image cost today, and the
                            // guard above is what makes reaching FalAiService's
                            // estimators here safe. Estimation is deliberately NOT
                            // on the GeneratesImages contract: putting it there
                            // would force every provider to implement dead code.
                            $modelId = $get('ai_image_model') ?? config('fal-ai.default_image_model');
                            $galleryCount = (int) ($get('ai_gallery_count') ?? 3);
                            $target = AIMediaTarget::tryFrom($get('ai_image_target') ?? 'gallery') ?? AIMediaTarget::Gallery;
                            $includesDescription = $target->includesDescription();

                            $estimatedPosts = 10;
                            $cost = FalAiService::estimateCost($modelId, $galleryCount, $estimatedPosts, $includesDescription);
                            $totalImages = FalAiService::estimateImageCount($galleryCount, $estimatedPosts, $includesDescription);

                            $formattedCost = number_format($cost, 2, ',', ' ').' '.config('money.defaultCurrency', 'EUR');

                            return "{$formattedCost} ({$totalImages} ".__('images total').' ~ '.__('per :count posts', ['count' => $estimatedPosts]).')';
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('ai_images_enabled')),
                ]),
        ];
    }
}
