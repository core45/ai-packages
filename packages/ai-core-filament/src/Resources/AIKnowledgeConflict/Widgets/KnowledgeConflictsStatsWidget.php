<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Widgets;

use Core45\AiCore\Enums\AIConflictStatus;
use Core45\AiCore\Enums\AIConflictType;
use Core45\AiCore\Models\AIKnowledgeConflict;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class KnowledgeConflictsStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $stats = Cache::remember('knowledge_conflicts_stats', now()->addMinutes(5), function () {
            $unresolved = AIKnowledgeConflict::unresolved()->count();
            $contradictions = AIKnowledgeConflict::unresolved()
                ->where('conflict_type', AIConflictType::DirectContradiction)
                ->count();
            $resolved = AIKnowledgeConflict::ofStatus(AIConflictStatus::Resolved)->count();
            $total = AIKnowledgeConflict::count();

            return [
                'unresolved' => $unresolved,
                'contradictions' => $contradictions,
                'resolved' => $resolved,
                'total' => $total,
            ];
        });

        return [
            Stat::make(__('ai_knowledge.conflict_stat.unresolved'), number_format($stats['unresolved'], 0, ',', ' '))
                ->description(__('ai_knowledge.conflict_stat.unresolved_description'))
                ->color($stats['unresolved'] > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make(__('ai_knowledge.conflict_stat.contradictions'), number_format($stats['contradictions'], 0, ',', ' '))
                ->description(__('ai_knowledge.conflict_stat.contradictions_description'))
                ->color($stats['contradictions'] > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedBolt),

            Stat::make(__('ai_knowledge.conflict_stat.resolved'), number_format($stats['resolved'], 0, ',', ' '))
                ->description(__('ai_knowledge.conflict_stat.resolved_description'))
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle),

            Stat::make(__('ai_knowledge.conflict_stat.total'), number_format($stats['total'], 0, ',', ' '))
                ->description(__('ai_knowledge.conflict_stat.total_description'))
                ->color('gray')
                ->icon(Heroicon::OutlinedChartBar),
        ];
    }
}
