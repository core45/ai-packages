<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeGap\Widgets;

use Core45\AiCore\Services\GapDetectionService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class KnowledgeGapsStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $stats = Cache::remember('knowledge_gaps_stats', now()->addMinutes(5), function () {
            $service = app(GapDetectionService::class);

            return $service->getStats();
        });

        return [
            Stat::make(__('ai-core::ai_knowledge.gap_status.open'), $this->formatCount($stats['open']))
                ->description(__('Questions needing answers'))
                ->color('warning')
                ->icon(Heroicon::OutlinedQuestionMarkCircle),

            Stat::make(__('Frequent Gaps'), $this->formatCount($stats['frequent']))
                ->description(__('Asked 3+ times'))
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make(__('ai-core::ai_knowledge.gap_status.resolved'), $this->formatCount($stats['resolved']))
                ->description(__('Gaps with answers created'))
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle),

            Stat::make(__('Total Gaps'), $this->formatCount($stats['total']))
                ->description(__('All detected gaps'))
                ->color('gray')
                ->icon(Heroicon::OutlinedChartBar),
        ];
    }

    /**
     * Format a count for display.
     *
     * Neither of the two versions this file has had was right for a package.
     * The original called the host's global `format_number()`, which is defined
     * in the consuming app — coupling no import-based check can see, and a
     * "call to undefined function" the first time the widget rendered anywhere
     * else. 00bb replaced it with `Number::format()`, which resolves separators
     * from the intl locale and so renders "26.083" under es_ES, against this
     * project's European convention of a space for thousands.
     *
     * Reading the same config keys the host helper reads keeps an app that
     * configures them, and gives everyone else the intended default.
     */
    private function formatCount(int|float $count): string
    {
        return number_format(
            $count,
            0,
            config('money.number_format.decimal_separator', ','),
            config('money.number_format.thousands_separator', ' '),
        );
    }
}
