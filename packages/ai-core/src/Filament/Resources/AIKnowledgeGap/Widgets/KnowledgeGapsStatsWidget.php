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
            Stat::make(__('ai_knowledge.gap_status.open'), format_number($stats['open']))
                ->description(__('Questions needing answers'))
                ->color('warning')
                ->icon(Heroicon::OutlinedQuestionMarkCircle),

            Stat::make(__('Frequent Gaps'), format_number($stats['frequent']))
                ->description(__('Asked 3+ times'))
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make(__('ai_knowledge.gap_status.resolved'), format_number($stats['resolved']))
                ->description(__('Gaps with answers created'))
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle),

            Stat::make(__('Total Gaps'), format_number($stats['total']))
                ->description(__('All detected gaps'))
                ->color('gray')
                ->icon(Heroicon::OutlinedChartBar),
        ];
    }
}
