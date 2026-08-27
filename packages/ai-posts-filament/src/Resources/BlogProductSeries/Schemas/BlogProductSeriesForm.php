<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogProductSeries\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BlogProductSeriesForm
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
                Section::make(__('Series Details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('profile_id')
                            ->relationship('profile', 'name')
                            ->searchable()
                            ->required()
                            ->label(__('Profile'))
                            ->columnSpanFull(),

                        Select::make('target_type')
                            ->options([
                                'product' => __('Product'),
                                'category' => __('Category'),
                                'tag' => __('Tag'),
                            ])
                            ->required()
                            ->label(__('Target Type')),

                        TextInput::make('total_posts')
                            ->numeric()
                            ->required()
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(50)
                            ->label(__('Total Posts')),

                        TextInput::make('interval_days')
                            ->numeric()
                            ->required()
                            ->default(7)
                            ->minValue(1)
                            ->label(__('Interval (days)')),
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
                    ->label('ID')
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
                Toggle::make('auto_publish')
                    ->label(__('Auto Publish'))
                    ->default(false),
            ])
            ->columnSpan(['lg' => 1]);
    }
}
