<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\Schemas;

use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BlogExternalLinkForm
{
    public static function configure(Schema $schema): Schema
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
                        TextInput::make('url')
                            ->required()
                            ->url()
                            ->maxLength(2048)
                            ->label(__('URL'))
                            ->columnSpanFull(),

                        TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->label(__('Label'))
                            ->columnSpanFull(),

                        TextInput::make('domain')
                            ->maxLength(255)
                            ->label(__('Domain')),

                        TextInput::make('anchor_text_suggestion')
                            ->maxLength(255)
                            ->label(__('Anchor Text Suggestion')),

                        Select::make('profile_id')
                            ->relationship('profile', 'name')
                            ->searchable()
                            ->label(__('Profile'))
                            ->placeholder(__('Global (no profile)'))
                            ->columnSpanFull(),
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
                self::infoSection(),
                self::settingsSection(),
            ])
            ->columnSpan(['lg' => 1]);
    }

    private static function infoSection(): Section
    {
        return Section::make()
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
            ->columnSpan(['lg' => 1]);
    }

    private static function settingsSection(): Section
    {
        return Section::make(__('Settings'))
            ->schema([
                Select::make('status')
                    // BlogExternalLinkStatus does not implement Filament's HasLabel, so
                    // ->options(BlogExternalLinkStatus::class) would render raw case names.
                    // Build the value => label map explicitly instead.
                    ->options(fn (): array => collect(BlogExternalLinkStatus::cases())
                        ->mapWithKeys(fn (BlogExternalLinkStatus $case): array => [$case->value => $case->getLabel()])
                        ->all())
                    ->default(BlogExternalLinkStatus::Active->value)
                    ->required()
                    ->label(__('Status')),
            ])
            ->columnSpan(['lg' => 1]);
    }
}
