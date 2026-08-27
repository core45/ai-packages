<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogTopicProfiles\Schemas;

use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Core45\AiPosts\Enums\CitationMode;
use Core45\AiPosts\Enums\WorkflowMode;
use Core45\AiPosts\Models\ContentModule;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class BlogTopicProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('ProfileTabs')
                ->columnSpanFull()
                ->tabs([
                    self::profileTab(),
                    self::siteProfileTab(),
                    self::languageTab(),
                    self::modulesTab(),
                    self::qualityGateTab(),
                    self::researchConfigTab(),
                ]),
        ]);
    }

    private static function profileTab(): Tab
    {
        return Tab::make(__('Profile'))
            ->columns(3)
            ->schema([
                self::leftColumn(),
                self::rightColumn(),
            ]);
    }

    private static function leftColumn(): Group
    {
        return Group::make()
            ->schema([
                Section::make(__('Profile Details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TagsInput::make('seed_keywords')
                            ->label(__('Seed Keywords'))
                            ->placeholder(__('Add keyword...'))
                            ->columnSpanFull(),

                        TagsInput::make('excluded_topics')
                            ->label(__('Excluded Topics'))
                            ->placeholder(__('Topics to exclude...'))
                            ->columnSpanFull(),

                        Textarea::make('constraints')
                            ->label(__('Constraints'))
                            ->placeholder(__('Optional instructions for topic generation...'))
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('brand_narrative')
                            ->label(__('Brand Narrative / Company Instructions'))
                            ->placeholder(__('Company story, founder bios, unique selling points, brand voice, tour/product list... This text is injected into every module\'s system prompt as context.'))
                            ->helperText(__('Persistent company context injected into every content module — equivalent to ChatGPT project instructions.'))
                            ->rows(8)
                            ->columnSpanFull(),

                        // Routed through the package's own SERP contract
                        // rather than the host's `SerpApiClient` — a package
                        // must not import a host service.
                        Toggle::make('config_json.serpapi_enrichment')
                            ->label(__('Enrich with SerpAPI'))
                            ->helperText(__('Use Google Autocomplete and Trends to expand keyword research.'))
                            ->inline(false)
                            ->visible(fn (): bool => app(ProvidesTopicTrends::class)->isEnabled())
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
                TextEntry::make('last_refreshed_at')
                    ->label(__('Last Refreshed'))
                    ->date(),
                TextEntry::make('created_at')
                    ->label(__('Created at')),
                TextEntry::make('updated_at')
                    ->label(__('Last modified at')),
            ])
            ->columns(2)
            ->columnSpan(['lg' => 1]);
    }

    private static function siteProfileTab(): Tab
    {
        return Tab::make(__('Site Profile'))
            ->schema([
                Section::make(__('AI-Generated Site Analysis'))
                    ->description(__('These values are generated by "Refresh Profile" from your product catalog and existing content. You can edit them here to correct the AI\'s analysis. Clicking "Refresh Profile" on the View page will overwrite your changes.'))
                    ->schema([
                        TagsInput::make('profile_json.themes')
                            ->label(__('Themes'))
                            ->helperText(__('Main content themes detected on your site.'))
                            ->placeholder(__('Add theme...'))
                            ->columnSpanFull(),

                        TagsInput::make('profile_json.verticals')
                            ->label(__('Industry Verticals'))
                            ->helperText(__('Product verticals and industries your site serves.'))
                            ->placeholder(__('Add vertical...'))
                            ->columnSpanFull(),

                        Textarea::make('profile_json.target_audience')
                            ->label(__('Target Audience'))
                            ->helperText(__('Who your site\'s content is aimed at.'))
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('profile_json.brand_voice')
                            ->label(__('Brand Voice (AI-observed)'))
                            ->helperText(__('How the AI perceives your current content tone. This is separate from Brand Narrative — that is what you tell the AI, this is what it detected.'))
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('profile_json.content_strengths')
                            ->label(__('Content Strengths'))
                            ->helperText(__('What your site covers well, according to the AI. One item per line.'))
                            ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode("\n", $state) : (string) ($state ?? ''))
                            ->dehydrateStateUsing(fn (?string $state): array => $state !== null && $state !== '' ? array_values(array_filter(array_map('trim', explode("\n", $state)))) : [])
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('profile_json.content_gaps')
                            ->label(__('Content Gaps'))
                            ->helperText(__('Topics and areas your site should cover but currently doesn\'t. One item per line.'))
                            ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode("\n", $state) : (string) ($state ?? ''))
                            ->dehydrateStateUsing(fn (?string $state): array => $state !== null && $state !== '' ? array_values(array_filter(array_map('trim', explode("\n", $state)))) : [])
                            ->rows(4)
                            ->columnSpanFull(),

                        TagsInput::make('profile_json.competitive_keywords')
                            ->label(__('Competitive Keywords (AI-discovered)'))
                            ->helperText(__('Keywords the AI identified from your catalog. This is separate from Seed Keywords — those are your input, these are AI-discovered.'))
                            ->placeholder(__('Add keyword...'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function languageTab(): Tab
    {
        /** @var array<string, array<string, string>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $localeOptions = [];
        foreach ($supportedLocales as $code => $localeData) {
            $localeOptions[$code] = ($localeData['name'] ?? $code).' ('.$code.')';
        }

        return Tab::make(__('Language & Locale'))
            ->schema([
                Section::make(__('Language Settings'))
                    ->schema([
                        Select::make('primary_locale')
                            ->label(__('Primary Locale'))
                            ->options($localeOptions)
                            ->required()
                            ->helperText(__('The main language for generated content.')),

                        Select::make('citation_mode')
                            ->label(__('Citation Mode'))
                            // CitationMode does not implement Filament's
                            // HasLabel (removed deliberately, and it has no
                            // `options()` static), so the value => label map
                            // is built explicitly here.
                            ->options(fn (): array => collect(CitationMode::cases())
                                ->mapWithKeys(fn (CitationMode $case): array => [$case->value => $case->getLabel()])
                                ->all())
                            ->default(CitationMode::Render->value)
                            ->helperText(__('How source citations are handled in generated content.')),

                        TextEntry::make('target_translation_locales')
                            ->label(__('Target Translation Locales'))
                            ->state(function ($record) use ($localeOptions): string {
                                if ($record === null) {
                                    return __('Save the profile first to see translation targets.');
                                }

                                $primary = $record->primary_locale;
                                $targets = array_filter(
                                    $localeOptions,
                                    fn (string $_label, string $code): bool => $code !== $primary,
                                    ARRAY_FILTER_USE_BOTH,
                                );

                                if ($targets === []) {
                                    return __('No other locales configured.');
                                }

                                return implode(', ', $targets);
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function modulesTab(): Tab
    {
        $moduleOptions = ContentModule::query()
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->toArray();

        return Tab::make(__('Modules'))
            ->schema([
                Section::make(__('Workflow'))
                    ->schema([
                        Select::make('modules_config.workflow_mode')
                            ->label(__('Workflow Mode'))
                            // WorkflowMode does not implement Filament's
                            // HasLabel (removed deliberately, and it has no
                            // `options()` static), so the value => label map
                            // is built explicitly here.
                            ->options(fn (): array => collect(WorkflowMode::cases())
                                ->mapWithKeys(fn (WorkflowMode $case): array => [$case->value => $case->getLabel()])
                                ->all())
                            ->default(WorkflowMode::Automatic->value)
                            ->helperText(__('Controls when the pipeline pauses for review.')),

                        Toggle::make('modules_config.checkpoint_after_research')
                            ->label(__('Checkpoint After Research'))
                            ->helperText(__('Pause for review after research completes (only in semi-automatic mode).')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Module Pipeline'))
                    ->schema([
                        Repeater::make('modules_config.modules')
                            ->label(__('Modules'))
                            ->schema([
                                Select::make('module_slug')
                                    ->label(__('Module'))
                                    ->options($moduleOptions)
                                    ->required(),

                                TextInput::make('order')
                                    ->label(__('Order'))
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),

                                Toggle::make('enabled')
                                    ->label(__('Enabled'))
                                    ->default(true),

                                Toggle::make('checkpoint')
                                    ->label(__('Checkpoint'))
                                    ->helperText(__('Pause after this module (semi-automatic mode).')),
                            ])
                            ->columns(3)
                            ->default(self::defaultModuleItems())
                            ->afterStateHydrated(function (Repeater $component, ?array $state): void {
                                if ($state === null || $state === []) {
                                    $component->state(self::defaultModuleItems());
                                }
                            })
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['module_slug'] ?? null)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function qualityGateTab(): Tab
    {
        return Tab::make(__('Quality Gate'))
            ->schema([
                Section::make(__('Quality Gate'))
                    ->schema([
                        TextInput::make('quality_config.quality_threshold')
                            ->label(__('Quality Threshold'))
                            ->numeric()
                            ->default(6.0)
                            ->afterStateHydrated(fn (TextInput $component, mixed $state) => $component->state($state ?? config('blog-pipeline.quality_threshold', 6.0)))
                            ->minValue(1)
                            ->maxValue(10)
                            ->step(0.5)
                            ->helperText(__('Minimum quality score (1-10) to pass a module.')),

                        TextInput::make('quality_config.max_retry_attempts')
                            ->label(__('Max Retry Attempts'))
                            ->numeric()
                            ->default(2)
                            ->afterStateHydrated(fn (TextInput $component, mixed $state) => $component->state($state ?? config('blog-pipeline.max_retry_attempts', 2)))
                            ->minValue(0)
                            ->maxValue(5),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function researchConfigTab(): Tab
    {
        return Tab::make(__('Research Config'))
            ->schema([
                Section::make(__('Competitor Scraping'))
                    ->schema([
                        Toggle::make('research_config.enable_competitor_scraping')
                            ->label(__('Enable Competitor Scraping'))
                            ->helperText(__('Scrape competitor pages for content analysis.'))
                            ->inline(false),
                    ])
                    ->columnSpanFull(),

                Section::make(__('Data Sources'))
                    ->description(__('Select which SEO data sources to use during research.'))
                    ->schema([
                        Toggle::make('research_config.enable_serp_analysis')
                            ->label(__('SERP Analysis'))
                            ->inline(false),

                        Toggle::make('research_config.enable_gsc')
                            ->label(__('Google Search Console'))
                            ->inline(false),

                        Toggle::make('research_config.enable_bing')
                            ->label(__('Bing Webmaster'))
                            ->inline(false),

                        Toggle::make('research_config.enable_wincher')
                            ->label(__('Wincher'))
                            ->inline(false),

                        Toggle::make('research_config.enable_semrush')
                            ->label(__('SEMrush'))
                            ->inline(false),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make(__('Research Depth'))
                    ->schema([
                        TextInput::make('research_config.competitor_count')
                            ->label(__('Competitor Count'))
                            ->numeric()
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(20)
                            ->helperText(__('Number of competitor pages to analyze.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Custom Instructions'))
                    ->schema([
                        Textarea::make('research_config.custom_instructions')
                            ->label(__('Custom Research Instructions'))
                            ->placeholder(__('Optional instructions for the research phase...'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultModuleItems(): array
    {
        $slugs = [
            'expert-content',
            'case-study',
            'faq',
            'common-mistakes',
            'myths',
            'comparisons',
            'insider-insights',
            'checklist',
            'trends',
            'conclusion',
        ];

        return array_map(fn (string $slug, int $index): array => [
            'module_slug' => $slug,
            'order' => $index,
            'enabled' => true,
            'checkpoint' => false,
        ], $slugs, array_keys($slugs));
    }
}
