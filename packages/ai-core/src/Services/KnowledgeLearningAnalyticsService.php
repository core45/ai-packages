<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Carbon\Carbon;
use Core45\AiCore\Enums\AIKnowledgeType;
use Core45\AiCore\Models\AIKnowledgeGap;
use Core45\AiCore\Models\AILearnedKnowledge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Analytics service for AI Knowledge Learning System.
 *
 * Provides insights into:
 * - Learning effectiveness (acceptance rates, sources)
 * - Knowledge usage patterns
 * - Gap detection & resolution
 * - System health metrics
 */
class KnowledgeLearningAnalyticsService
{
    /**
     * Get comprehensive dashboard summary.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(): array
    {
        return [
            'overview' => $this->getOverviewMetrics(),
            'learning' => $this->getLearningMetrics(),
            'usage' => $this->getUsageMetrics(),
            'gaps' => $this->getGapMetrics(),
        ];
    }

    /**
     * Get high-level overview metrics.
     *
     * @return array<string, mixed>
     */
    public function getOverviewMetrics(): array
    {
        $total = AILearnedKnowledge::count();
        $active = AILearnedKnowledge::where('enabled', true)->count();
        $critical = AILearnedKnowledge::where('enabled', true)->critical()->count();
        $used = AILearnedKnowledge::where('enabled', true)->where('use_count', '>', 0)->count();

        $openGaps = AIKnowledgeGap::open()->count();
        $frequentGaps = AIKnowledgeGap::open()->frequent()->count();

        return [
            'total_knowledge' => $total,
            'active_knowledge' => $active,
            'critical_knowledge' => $critical,
            'used_knowledge' => $used,
            'unused_knowledge' => $active - $used,
            'usage_rate' => $active > 0 ? round(($used / $active) * 100, 1) : 0,
            'open_gaps' => $openGaps,
            'frequent_gaps' => $frequentGaps,
        ];
    }

    /**
     * Get learning effectiveness metrics.
     *
     * @return array<string, mixed>
     */
    public function getLearningMetrics(): array
    {
        // Source distribution
        $bySource = AILearnedKnowledge::query()
            ->select('source_type', DB::raw('COUNT(*) as count'))
            ->groupBy('source_type')
            ->get()
            ->pluck('count', 'source_type')
            ->toArray();

        // Learning velocity (new knowledge per week for last 12 weeks)
        $velocity = $this->getLearningVelocity(12);

        // Type distribution
        /** @var Collection<int, object{type: AIKnowledgeType|string, count: int}> $typeResults */
        $typeResults = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        $byType = $typeResults
            ->mapWithKeys(fn (object $item): array => [
                $item->type instanceof AIKnowledgeType ? $item->type->value : (string) $item->type => (int) $item->count,
            ])
            ->toArray();

        return [
            'by_source' => $bySource,
            'by_type' => $byType,
            'velocity' => $velocity,
            'total_learned' => AILearnedKnowledge::count(),
            'learned_this_week' => AILearnedKnowledge::where('created_at', '>=', now()->subWeek())->count(),
            'learned_this_month' => AILearnedKnowledge::where('created_at', '>=', now()->subMonth())->count(),
        ];
    }

    /**
     * Get knowledge usage metrics.
     *
     * @return array<string, mixed>
     */
    public function getUsageMetrics(): array
    {
        $mostUsed = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->where('use_count', '>', 0)
            ->orderByDesc('use_count')
            ->limit(10)
            ->get(['id', 'title', 'type', 'use_count', 'last_used_at'])
            ->map(fn (AILearnedKnowledge $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type->value,
                'use_count' => $item->use_count,
                'last_used_at' => $item->last_used_at?->diffForHumans(),
            ]);

        $leastUsed = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->where('use_count', 0)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'title', 'type', 'created_at'])
            ->map(fn (AILearnedKnowledge $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type->value,
                'created_at' => $item->created_at?->diffForHumans(),
            ]);

        // Usage by tier
        /** @var Collection<int, object{tier: string, count: int, total_uses: string|null, avg_uses: string|null}> $tierResults */
        $tierResults = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select(
                'tier',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(use_count) as total_uses'),
                DB::raw('AVG(use_count) as avg_uses')
            )
            ->groupBy('tier')
            ->get();

        $byTier = $tierResults
            ->mapWithKeys(fn (object $item): array => [
                $item->tier => [
                    'count' => (int) $item->count,
                    'total_uses' => (int) ($item->total_uses ?? 0),
                    'avg_uses' => round((float) ($item->avg_uses ?? 0), 1),
                ],
            ])
            ->toArray();

        // Usage by audience
        /** @var Collection<int, object{audience: string, count: int, total_uses: string|null}> $audienceResults */
        $audienceResults = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select(
                'audience',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(use_count) as total_uses')
            )
            ->groupBy('audience')
            ->get();

        $byAudience = $audienceResults
            ->mapWithKeys(fn (object $item): array => [
                $item->audience => [
                    'count' => (int) $item->count,
                    'total_uses' => (int) ($item->total_uses ?? 0),
                ],
            ])
            ->toArray();

        $usageTrend = $this->getUsageTrend(30);

        return [
            'most_used' => $mostUsed,
            'least_used' => $leastUsed,
            'by_tier' => $byTier,
            'by_audience' => $byAudience,
            'usage_trend' => $usageTrend,
            'total_uses' => AILearnedKnowledge::sum('use_count'),
        ];
    }

    /**
     * Get gap detection and resolution metrics.
     *
     * @return array<string, mixed>
     */
    public function getGapMetrics(): array
    {
        $total = AIKnowledgeGap::count();
        $open = AIKnowledgeGap::open()->count();
        $resolved = AIKnowledgeGap::resolved()->count();
        $ignored = AIKnowledgeGap::ignored()->count();

        $resolutionRate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

        // Average time to resolve (in days)
        $avgResolutionTime = AIKnowledgeGap::query()
            ->resolved()
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, created_at, resolved_at)) as avg_days')
            ->value('avg_days');

        // Most frequent open gaps
        $frequentGaps = AIKnowledgeGap::query()
            ->open()
            ->orderByDesc('occurrence_count')
            ->limit(10)
            ->get(['id', 'question', 'occurrence_count', 'audience', 'confidence_score', 'last_occurred_at'])
            ->map(fn ($gap) => [
                'id' => $gap->id,
                'question' => $gap->question,
                'occurrence_count' => $gap->occurrence_count,
                'audience' => $gap->audience,
                'confidence_score' => (float) $gap->confidence_score,
                'last_occurred_at' => $gap->last_occurred_at?->diffForHumans(),
            ]);

        // Gap trends over time (last 30 days)
        $gapTrend = $this->getGapDetectionTrend(30);

        // Gap funnel (detection → resolution)
        $funnel = [
            'detected' => $total,
            'open' => $open,
            'resolved' => $resolved,
            'ignored' => $ignored,
        ];

        return [
            'total_gaps' => $total,
            'open_gaps' => $open,
            'resolved_gaps' => $resolved,
            'ignored_gaps' => $ignored,
            'resolution_rate' => $resolutionRate,
            'avg_resolution_days' => $avgResolutionTime !== null ? round($avgResolutionTime, 1) : null,
            'frequent_gaps' => $frequentGaps,
            'gap_trend' => $gapTrend,
            'funnel' => $funnel,
        ];
    }

    /**
     * Get learning velocity (new knowledge per week).
     *
     * Rows are aggregated per day in SQL so the query stays portable across database
     * drivers, then rolled up into ISO year-week buckets here.
     *
     * @return Collection<int, array{week: string, count: int}>
     */
    public function getLearningVelocity(int $weeks = 12): Collection
    {
        /** @var Collection<int, stdClass> $results */
        $results = DB::table('ai_learned_knowledge')
            ->where('created_at', '>=', now()->subWeeks($weeks))
            ->select(
                DB::raw('date(created_at) as day'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('day')
            ->get();

        $weeklyCounts = [];

        foreach ($results as $row) {
            $week = Carbon::parse($row->day)->format('o-W');
            $weeklyCounts[$week] = ($weeklyCounts[$week] ?? 0) + (int) $row->count;
        }

        ksort($weeklyCounts);

        return collect($weeklyCounts)
            ->map(fn (int $count, string $week): array => [
                'week' => $week,
                'count' => $count,
            ])
            ->values();
    }

    /**
     * Get usage trend over time (last N days).
     *
     * @return Collection<int, array{date: string, uses: int}>
     */
    public function getUsageTrend(int $days = 30): Collection
    {
        // This requires tracking usage events separately
        // For now, we'll show knowledge creation trend as a proxy
        /** @var Collection<int, stdClass> $results */
        $results = DB::table('ai_learned_knowledge')
            ->where('last_used_at', '>=', now()->subDays($days))
            ->select(
                DB::raw('DATE(last_used_at) as date'),
                DB::raw('COUNT(*) as uses')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $results->map(fn (stdClass $row): array => [
            'date' => (string) $row->date,
            'uses' => (int) $row->uses,
        ]);
    }

    /**
     * Get gap detection trend (last N days).
     *
     * @return Collection<int, array{date: string, count: int}>
     */
    public function getGapDetectionTrend(int $days = 30): Collection
    {
        /** @var Collection<int, stdClass> $results */
        $results = DB::table('ai_knowledge_gaps')
            ->where('created_at', '>=', now()->subDays($days))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $results->map(fn (stdClass $row): array => [
            'date' => (string) $row->date,
            'count' => (int) $row->count,
        ]);
    }

    /**
     * Get effectiveness score (0-100).
     * Combines multiple metrics into a single score.
     */
    public function getEffectivenessScore(): float
    {
        $overview = $this->getOverviewMetrics();
        $gaps = $this->getGapMetrics();

        // Factors:
        // 1. Usage rate (40%) - how much knowledge is being used
        // 2. Resolution rate (30%) - how many gaps are being resolved
        // 3. Active knowledge (20%) - ratio of active to total
        // 4. Gap frequency (10%) - fewer frequent gaps is better

        $usageScore = $overview['usage_rate'] * 0.4;
        $resolutionScore = $gaps['resolution_rate'] * 0.3;
        $activeScore = $overview['total_knowledge'] > 0
            ? ($overview['active_knowledge'] / $overview['total_knowledge']) * 100 * 0.2
            : 0;

        // Inverse score for frequent gaps (fewer is better)
        $gapScore = $overview['open_gaps'] > 0
            ? (1 - min($overview['frequent_gaps'] / $overview['open_gaps'], 1)) * 100 * 0.1
            : 10;

        return round($usageScore + $resolutionScore + $activeScore + $gapScore, 1);
    }

    /**
     * Get knowledge coverage report.
     * Shows which types of knowledge are well-covered vs gaps.
     *
     * @return array<string, mixed>
     */
    public function getCoverageReport(): array
    {
        /** @var Collection<int, object{type: AIKnowledgeType|string, count: int}> $coverageTypeResults */
        $coverageTypeResults = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        $knowledgeByType = $coverageTypeResults
            ->mapWithKeys(fn (object $item): array => [
                $item->type instanceof AIKnowledgeType ? $item->type->value : (string) $item->type => (int) $item->count,
            ])
            ->toArray();

        $gapsByAudience = AIKnowledgeGap::query()
            ->open()
            ->select('audience', DB::raw('COUNT(*) as count'))
            ->groupBy('audience')
            ->get()
            ->pluck('count', 'audience')
            ->toArray();

        return [
            'knowledge_by_type' => $knowledgeByType,
            'gaps_by_audience' => $gapsByAudience,
            'recommendations' => $this->generateRecommendations($knowledgeByType, $gapsByAudience),
        ];
    }

    /**
     * Generate actionable recommendations based on analytics.
     *
     * @param  array<string, int>  $knowledgeByType
     * @param  array<string, int>  $gapsByAudience
     * @return array<int, array{type: string, message: string, priority: string}>
     */
    protected function generateRecommendations(array $knowledgeByType, array $gapsByAudience): array
    {
        $recommendations = [];

        // Check for unused knowledge
        $unused = AILearnedKnowledge::where('enabled', true)->where('use_count', 0)->count();
        if ($unused > 10) {
            $recommendations[] = [
                'type' => 'unused_knowledge',
                'message' => 'You have '.number_format($unused, 0, ',', ' ').' knowledge items that have never been used. Consider reviewing their relevance.',
                'priority' => 'low',
            ];
        }

        // Check for frequent gaps
        $frequentGaps = AIKnowledgeGap::open()->frequent()->count();
        if ($frequentGaps > 0) {
            $recommendations[] = [
                'type' => 'frequent_gaps',
                'message' => 'You have '.number_format($frequentGaps, 0, ',', ' ').' frequently asked questions with no answers. Prioritize resolving these.',
                'priority' => 'high',
            ];
        }

        // Check for low resolution rate
        $resolutionRate = $this->getGapMetrics()['resolution_rate'];
        if ($resolutionRate < 50) {
            $recommendations[] = [
                'type' => 'low_resolution',
                'message' => 'Only '.number_format($resolutionRate, 1, ',', ' ').'% of detected gaps have been resolved. Review open gaps regularly.',
                'priority' => 'medium',
            ];
        }

        // Check for audience coverage
        $customerGaps = $gapsByAudience['customer'] ?? 0;
        if ($customerGaps > 5) {
            $recommendations[] = [
                'type' => 'customer_gaps',
                'message' => 'Customers have '.number_format($customerGaps, 0, ',', ' ').' unanswered questions. This impacts customer experience.',
                'priority' => 'high',
            ];
        }

        // Check for critical knowledge count
        $critical = AILearnedKnowledge::where('enabled', true)->critical()->count();
        if ($critical > 50) {
            $recommendations[] = [
                'type' => 'too_many_critical',
                'message' => 'You have '.number_format($critical, 0, ',', ' ').' critical knowledge items. Consider demoting some to contextual to reduce prompt size.',
                'priority' => 'low',
            ];
        }

        return $recommendations;
    }
}
