<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\Pages;

use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Core45\AiPosts\Filament\Resources\BlogExternalLinks\BlogExternalLinkResource;
use Core45\AiPosts\Models\BlogExternalLink;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewBlogExternalLink extends ViewRecord
{
    protected static string $resource = BlogExternalLinkResource::class;

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
                Section::make(__('Link Details'))
                    ->schema([
                        TextEntry::make('url')
                            ->label(__('URL'))
                            ->url(fn (BlogExternalLink $record): string => $record->url, shouldOpenInNewTab: true)
                            ->columnSpanFull(),

                        TextEntry::make('domain')
                            ->label(__('Domain')),

                        TextEntry::make('label')
                            ->label(__('Label')),

                        TextEntry::make('anchor_text_suggestion')
                            ->label(__('Anchor Text Suggestion'))
                            ->columnSpanFull(),

                        TextEntry::make('profile.name')
                            ->placeholder(__('Global'))
                            ->label(__('Profile')),

                        TextEntry::make('usage_count')
                            ->label(__('Usage Count')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Block Info'))
                    ->schema([
                        TextEntry::make('blocked_reason')
                            ->label(__('Blocked Reason')),

                        TextEntry::make('blockedByUser.name')
                            ->label(__('Blocked By')),

                        TextEntry::make('blocked_at')
                            ->dateTime()
                            ->label(__('Blocked At')),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (BlogExternalLink $record): bool => $record->status === BlogExternalLinkStatus::Blocked),
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
                            ->label(__('Created at')),
                        TextEntry::make('updated_at')
                            ->label(__('Last modified at')),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 1]),

                Section::make(__('Status'))
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->label(__('Status')),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columnSpan(['lg' => 1]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('block')
                ->label(__('Block Link'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (BlogExternalLink $record): bool => $record->status !== BlogExternalLinkStatus::Blocked)
                ->action(function (): void {
                    /** @var BlogExternalLink $link */
                    $link = $this->record;

                    $link->update([
                        'status' => BlogExternalLinkStatus::Blocked,
                        'blocked_by' => Auth::id(),
                        'blocked_at' => now(),
                        'blocked_reason' => 'Manually blocked by admin',
                    ]);

                    Notification::make()
                        ->title(__('Link blocked'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'blocked_by', 'blocked_at', 'blocked_reason']);
                }),

            Action::make('activate')
                ->label(__('Activate Link'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (BlogExternalLink $record): bool => $record->status !== BlogExternalLinkStatus::Active)
                ->action(function (): void {
                    /** @var BlogExternalLink $link */
                    $link = $this->record;

                    $link->update([
                        'status' => BlogExternalLinkStatus::Active,
                        'blocked_by' => null,
                        'blocked_at' => null,
                        'blocked_reason' => null,
                    ]);

                    Notification::make()
                        ->title(__('Link activated'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'blocked_by', 'blocked_at', 'blocked_reason']);
                }),
        ];
    }
}
