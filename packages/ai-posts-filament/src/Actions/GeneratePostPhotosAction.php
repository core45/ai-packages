<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Actions;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Models\ImageDirectorProfile;
use Core45\AiCore\Models\ImageStyle;
use Core45\AiPosts\Jobs\GeneratePostPhotosJob;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class GeneratePostPhotosAction
{
    /**
     * `resolveImageDirectorProfile()` comes from `HasImageDirectorProfile`,
     * a trait a host model opts into — it is not part of the `AIContentable`
     * contract, so it must be called defensively for hosts that never added
     * the trait to their post model.
     */
    private static function resolveImageDirectorProfile(AIContentable&Model $record): ?ImageDirectorProfile
    {
        if (!method_exists($record, 'resolveImageDirectorProfile')) {
            return null;
        }

        return $record->resolveImageDirectorProfile();
    }

    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'generatePostPhotos')
            ->label(__('AI Generate Photos'))
            ->icon(Heroicon::OutlinedPhoto)
            ->color('warning')
            ->visible(fn (): bool => (bool) config('ai.assistant.blog_topics.images.enabled', false))
            ->modalHeading(__('AI Generate Photos'))
            ->schema([
                Toggle::make('generate_featured')
                    ->label(__('Generate Featured Image'))
                    ->default(false),
                Toggle::make('generate_gallery')
                    ->label(__('Generate Gallery Images'))
                    ->default(false),
                Toggle::make('generate_in_text')
                    ->label(__('Generate In-Text Images'))
                    ->default(false),
                Toggle::make('replace_existing')
                    ->label(__('Replace existing photos'))
                    ->default(false)
                    ->helperText(__('When off, AI will only add new images alongside existing ones.')),
                Select::make('image_director_profile_id')
                    ->label(__('AI Image Profile'))
                    ->options(fn (): array => ImageDirectorProfile::active()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (AIContentable&Model $record): ?int => self::resolveImageDirectorProfile($record)?->id),
                Select::make('style_slug')
                    ->label(__('Visual style'))
                    ->options(ImageStyle::options())
                    // `default_image_style_id` is a nullable FK, so
                    // `defaultImageStyle` genuinely can be null at runtime;
                    // `optional()` keeps that safe without a second `?->`,
                    // which Larastan considers unreachable on this relation.
                    ->default(fn (AIContentable&Model $record): ?string => optional(self::resolveImageDirectorProfile($record)?->defaultImageStyle)->slug
                        ?? ImageStyle::active()->orderBy('sort_order')->value('slug')),
            ])
            ->modalSubmitActionLabel(__('Generate'))
            ->action(function (array $data, AIContentable&Model $record): void {
                $generateFeatured = (bool) $data['generate_featured'];
                $generateGallery = (bool) $data['generate_gallery'];
                $generateInText = (bool) $data['generate_in_text'];

                if (!$generateFeatured && !$generateGallery && !$generateInText) {
                    Notification::make()
                        ->title(__('No options selected'))
                        ->body(__('Please select at least one image type to generate.'))
                        ->warning()
                        ->send();

                    return;
                }

                // Auth::id() is typed int|string|null by the guard contract,
                // but GeneratePostPhotosJob's $userId is int|null — narrow
                // here instead of widening the job's parameter type.
                $authId = Auth::id();

                GeneratePostPhotosJob::dispatch(
                    post: $record,
                    generateFeatured: $generateFeatured,
                    generateGallery: $generateGallery,
                    generateInText: $generateInText,
                    replaceExisting: (bool) $data['replace_existing'],
                    profileId: $data['image_director_profile_id'] ? (int) $data['image_director_profile_id'] : null,
                    styleSlug: $data['style_slug'] ?: null,
                    userId: is_numeric($authId) ? (int) $authId : null,
                );

                Notification::make()
                    ->title(__('Photo Generation Queued'))
                    ->body(__('AI photos will be generated in the background. You will be notified when complete.'))
                    ->success()
                    ->send();
            });
    }
}
