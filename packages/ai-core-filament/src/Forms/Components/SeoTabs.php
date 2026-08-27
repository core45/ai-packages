<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Closure;
use Core45\AiCore\Filament\Actions\AskAIAction;
use Doriiaan\FilamentAstrotomic\TranslatableTab;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

class SeoTabs
{
    /**
     * Create a complete SEO Tabs component with General, Social, Analysis, and GEO tabs.
     */
    public static function make(TranslatableTab $tab, string|Closure|null $imageUrl = null): Tabs
    {
        return Tabs::make('seo_tabs')
            ->tabs([
                self::generalTab($tab),
                self::socialTab($tab, $imageUrl),
                self::analysisTab($tab),
                self::geoTab($tab),
            ])
            ->columnSpanFull();
    }

    /**
     * General SEO tab with title, description, keywords, SERP preview, and cornerstone toggle.
     */
    private static function generalTab(TranslatableTab $tab): Tab
    {
        return Tab::make(__('General'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->schema([
                FocusKeyphraseInput::make($tab->makeName('focus_keyphrase')),
                SeoTitleInput::make($tab->makeName('seo_title')),
                SeoDescriptionInput::make($tab->makeName('seo_description')),
                TextInput::make($tab->makeName('seo_keywords'))
                    ->label(__('SEO Keywords'))
                    ->hintAction(AskAIAction::make())
                    ->columnSpanFull(),
                SerpPreview::make()
                    ->titleStatePath($tab->makeName('seo_title'))
                    ->descriptionStatePath($tab->makeName('seo_description'))
                    ->urlStatePath($tab->makeName('slug')),
                CornerstoneToggle::make()
                    ->locale($tab->getLocale()),
            ]);
    }

    /**
     * Social media preview tab with Open Graph and Twitter card previews.
     */
    private static function socialTab(TranslatableTab $tab, string|Closure|null $imageUrl = null): Tab
    {
        return Tab::make(__('Social'))
            ->icon(Heroicon::OutlinedShare)
            ->schema([
                SocialPreview::make()
                    ->ogTitleStatePath($tab->makeName('seo_title'))
                    ->ogDescriptionStatePath($tab->makeName('seo_description'))
                    ->ogImageUrl($imageUrl)
                    ->twitterImageUrl($imageUrl)
                    ->fallbackTitleStatePath($tab->makeName('title'))
                    ->fallbackDescriptionStatePath($tab->makeName('excerpt')),
            ]);
    }

    /**
     * Analysis tab with SEO and readability analysis panels.
     */
    private static function analysisTab(TranslatableTab $tab): Tab
    {
        return Tab::make(__('Analysis'))
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                SeoAnalysisPanel::make()
                    ->locale($tab->getLocale()),
                ReadabilityAnalysisPanel::make()
                    ->locale($tab->getLocale()),
            ]);
    }

    /**
     * GEO Audit tab with per-locale scoring breakdown.
     */
    private static function geoTab(TranslatableTab $tab): Tab
    {
        return Tab::make(__('GEO Audit'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                GeoAuditPanel::make()
                    ->locale($tab->getLocale()),
            ]);
    }
}
