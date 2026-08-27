<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge\Schemas;

use Core45\AiCore\Models\AILearnedKnowledge;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AIKnowledgeForm
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
                Section::make(__('ai_knowledge.section_content'))
                    ->description(__('ai_knowledge.section_content_description'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('ai_knowledge.field.title'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('ai_knowledge.placeholder.title'))
                            ->columnSpanFull(),

                        Textarea::make('content')
                            ->label(__('ai_knowledge.field.content'))
                            ->required()
                            ->rows(5)
                            ->placeholder(__('ai_knowledge.placeholder.content'))
                            ->columnSpanFull(),

                        TagsInput::make('keywords')
                            ->label(__('ai_knowledge.field.keywords'))
                            ->placeholder(__('ai_knowledge.placeholder.keywords'))
                            ->helperText(__('ai_knowledge.help.keywords'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->columnSpan(['lg' => 2]);
    }

    private static function rightColumn(): Group
    {
        return Group::make()
            ->schema([
                self::infoSection(),
                self::classificationSection(),
                self::settingsSection(),
            ])
            ->columnSpan(['lg' => 1]);
    }

    private static function infoSection(): Section
    {
        return Section::make()
            ->schema([
                TextEntry::make('id')->label('ID')->columnSpanFull(),
                TextEntry::make('use_count')
                    ->label(__('ai_knowledge.field.use_count'))
                    ->default(0),
                TextEntry::make('last_used_at')
                    ->label(__('ai_knowledge.field.last_used_at'))
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime(),
                TextEntry::make('creator.name')
                    ->label(__('ai_knowledge.field.created_by'))
                    ->default('-'),
            ])
            ->columns(2)
            ->columnSpan(['lg' => 1]);
    }

    private static function classificationSection(): Section
    {
        return Section::make(__('ai_knowledge.section_classification'))
            ->schema([
                Select::make('type')
                    ->label(__('ai_knowledge.field.type'))
                    ->options(AILearnedKnowledge::getTypeOptions())
                    ->default('fact')
                    ->required()
                    ->native(false),

                Select::make('tier')
                    ->label(__('ai_knowledge.field.tier'))
                    ->options(AILearnedKnowledge::getTierOptions())
                    ->default('contextual')
                    ->required()
                    ->native(false)
                    ->helperText(__('ai_knowledge.help.tier')),

                Select::make('audience')
                    ->label(__('ai_knowledge.field.audience'))
                    ->options(AILearnedKnowledge::getAudienceOptions())
                    ->default('public')
                    ->required()
                    ->native(false)
                    ->helperText(__('ai_knowledge.help.audience')),
            ])
            ->columnSpan(['lg' => 1]);
    }

    private static function settingsSection(): Section
    {
        return Section::make(__('Settings'))
            ->schema([
                Toggle::make('enabled')
                    ->label(__('ai_knowledge.field.enabled'))
                    ->default(true)
                    ->helperText(__('ai_knowledge.help.enabled')),
            ])
            ->columnSpan(['lg' => 1]);
    }
}
