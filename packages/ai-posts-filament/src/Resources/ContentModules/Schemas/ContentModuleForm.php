<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\ContentModules\Schemas;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiPosts\Models\ContentModule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ContentModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('ContentModuleTabs')
                ->columnSpanFull()
                ->tabs([
                    self::identityTab(),
                    self::promptsTab(),
                    self::qualityTab(),
                    self::dependenciesTab(),
                ]),
        ]);
    }

    private static function identityTab(): Tab
    {
        return Tab::make(__('Identity'))
            ->schema([
                Section::make(__('Module Identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, $set): void {
                                if ($operation === 'create' && $state !== null) {
                                    // Routed through the host-bound slug contract instead of the
                                    // host's own SlugGenerator — a package cannot import App\Services.
                                    $set('slug', app(GeneratesSlugs::class)->make($state));
                                }
                            })
                            ->columnSpanFull(),

                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ContentModule::class, 'slug')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label(__('Description'))
                            ->rows(3)
                            ->columnSpanFull(),

                        Select::make('output_format')
                            ->label(__('Output Format'))
                            ->options([
                                'html' => 'HTML',
                                'markdown' => 'Markdown',
                                'json' => 'JSON',
                            ])
                            ->required()
                            ->default('html'),

                        Toggle::make('is_built_in')
                            ->label(__('Built-in'))
                            ->helperText(__('Built-in modules cannot be deleted.'))
                            ->disabled(fn (?ContentModule $record): bool => $record !== null && $record->is_built_in),

                        Toggle::make('active')
                            ->label(__('Active'))
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function promptsTab(): Tab
    {
        return Tab::make(__('Prompts'))
            ->schema([
                Section::make(__('Prompt Templates'))
                    ->description(__('Available placeholders: {{topic_title}}, {{topic_intent}}, {{topic_outline}}, {{site_profile}}, {{brand_narrative}}, {{research_keywords}}, {{research_serp_analysis}}, {{research_questions}}, {{research_synthesis}}, {{research_sources}}, {{citation_instructions}}, {{previous_modules}}, {{previous_modules_detailed}}, {{previous_module_last}}, {{rag_context}}, {{product_context}}, {{internal_link_targets}}, {{language}}, {{style_rules}}, {{forbidden_patterns}}, {{learned_knowledge}}, {{profile_lessons}}'))
                    ->schema([
                        Textarea::make('system_prompt_template')
                            ->label(__('System Prompt Template'))
                            ->rows(12)
                            ->extraAttributes(['style' => 'font-family: monospace;'])
                            ->columnSpanFull(),

                        Textarea::make('user_prompt_template')
                            ->label(__('User Prompt Template'))
                            ->rows(12)
                            ->extraAttributes(['style' => 'font-family: monospace;'])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function qualityTab(): Tab
    {
        return Tab::make(__('Quality'))
            ->schema([
                Section::make(__('Quality Criteria'))
                    ->schema([
                        TextInput::make('quality_criteria.min_words')
                            ->label(__('Minimum Words'))
                            ->numeric()
                            ->minValue(0),

                        TextInput::make('quality_criteria.max_words')
                            ->label(__('Maximum Words'))
                            ->numeric()
                            ->minValue(0),

                        TagsInput::make('quality_criteria.required_elements')
                            ->label(__('Required Elements'))
                            ->placeholder(__('Add element...'))
                            ->helperText(__('Elements that must appear in the output (e.g., h2, h3, ul, blockquote)'))
                            ->columnSpanFull(),

                        TagsInput::make('quality_criteria.forbidden_patterns')
                            ->label(__('Forbidden Patterns'))
                            ->placeholder(__('Add pattern...'))
                            ->helperText(__('Patterns that must NOT appear in the output'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function dependenciesTab(): Tab
    {
        return Tab::make(__('Dependencies'))
            ->schema([
                Section::make(__('Module Dependencies'))
                    ->schema([
                        Toggle::make('requires_research')
                            ->label(__('Requires Research'))
                            ->helperText(__('This module needs research data as input.')),

                        Toggle::make('requires_previous_modules')
                            ->label(__('Requires Previous Modules'))
                            ->helperText(__('This module needs output from earlier modules.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
