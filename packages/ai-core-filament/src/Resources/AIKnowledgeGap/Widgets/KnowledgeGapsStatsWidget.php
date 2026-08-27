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
            Stat::make(__('ai_knowledge.gap_status.open'), $this->formatCount($stats['open']))
                ->description(__('Questions needing answers'))
                ->color('warning')
                ->icon(Heroicon::OutlinedQuestionMarkCircle),

            Stat::make(__('Frequent Gaps'), $this->formatCount($stats['frequent']))
                ->description(__('Asked 3+ times'))
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make(__('ai_knowledge.gap_status.resolved'), $this->formatCount($stats['resolved']))
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
     * These four calls used to be the host's global `format_number()` helper,
     * which is defined in the consuming app rather than in any package. A global
     * function is host coupling that the `use App\` check cannot see — it is not
     * an import — so it survived every gate and would have fataled with "call to
     * undefined function" the first time this widget rendered in a fresh app.
     *
     * The separators are read from the same config keys the host helper uses, so
     * an app that configures them keeps its formatting, and one that does not
     * gets the European default of a space for thousands and a comma for
     * decimals.
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
