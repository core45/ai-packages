<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Carbon\Carbon;
use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationMessage;
use Core45\AiCore\Models\AIKnowledgeGap;
use Core45\AiCore\Models\AILearnedKnowledge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Service for analyzing AI system usage patterns.
 *
 * Provides analytics for:
 * - Conversation volumes over time
 * - Peak usage hours
 * - Knowledge gap trends
 * - Popular topics
 */
class UsageAnalyticsService
{
    /**
     * Get comprehensive usage patterns for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function getUsagePatterns(): array
    {
        return [
            'conversations_by_day' => $this->getConversationsByDay(),
            'conversations_by_audience' => $this->getConversationsByAudience(),
            'peak_hours' => $this->getPeakHours(),
            'gap_trends' => $this->getGapTrends(),
            'knowledge_stats' => $this->getKnowledgeStats(),
            'response_times' => $this->getAverageResponseTimes(),
        ];
    }

    /**
     * Get conversation counts by day for the last 30 days.
     *
     * Uses `date()` (portable across MySQL/SQLite/Postgres) instead of the
     * MySQL-only spelling — mirrors KnowledgeLearningAnalyticsService's
     * portable aggregation pattern (audit E1 follow-up).
     *
     * @return Collection<int, array{date: string, count: int}>
     */
    public function getConversationsByDay(int $days = 30): Collection
    {
        /** @var Collection<int, stdClass> $results */
        $results = DB::table('ai_conversations')
            ->where('created_at', '>=', now()->subDays($days))
            ->select(
                DB::raw('date(created_at) as date'),
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
     * Get conversation breakdown by audience.
     *
     * @return Collection<int, array{audience: string, count: int, percentage: float}>
     */
    public function getConversationsByAudience(int $days = 30): Collection
    {
        /** @var Collection<int, stdClass> $results */
        $results = DB::table('ai_conversations')
            ->where('created_at', '>=', now()->subDays($days))
            ->select(
                'audience',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('audience')
            ->get();

        $total = $results->sum('count');

        return $results->map(fn (stdClass $row): array => [
            'audience' => (string) ($row->audience ?? 'unknown'),
            'count' => (int) $row->count,
            'percentage' => $total > 0 ? round(((int) $row->count / $total) * 100, 1) : 0.0,
        ]);
    }

    /**
     * Get peak usage hours.
     *
     * `HOUR()` is MySQL-only, so timestamps are pulled and rolled up into
     * hour buckets in PHP — the same portable roll-up pattern
     * KnowledgeLearningAnalyticsService::getLearningVelocity() uses for
     * week buckets (audit E1 follow-up).
     *
     * @return Collection<int, array{hour: int, count: int}>
     */
    public function getPeakHours(int $days = 30): Collection
    {
        $timestamps = DB::table('ai_conversations')
            ->where('created_at', '>=', now()->subDays($days))
            ->pluck('created_at');

        $hourlyCounts = [];

        foreach ($timestamps as $timestamp) {
            $hour = (int) Carbon::parse((string) $timestamp)->format('G');
            $hourlyCounts[$hour] = ($hourlyCounts[$hour] ?? 0) + 1;
        }

        ksort($hourlyCounts);

        return collect($hourlyCounts)
            ->map(fn (int $count, int $hour): array => [
                'hour' => $hour,
                'count' => $count,
            ])
            ->values();
    }

    /**
     * Get top knowledge gaps by occurrence.
     *
     * @return Collection<int, array{question: string, occurrence_count: int, audience: string}>
     */
    public function getGapTrends(int $limit = 10): Collection
    {
        return AIKnowledgeGap::query()
            ->open()
            ->select('question', 'occurrence_count', 'audience')
            ->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get()
            ->map(fn (AIKnowledgeGap $gap): array => [
                'question' => $gap->question,
                'occurrence_count' => $gap->occurrence_count,
                'audience' => $gap->audience,
            ]);
    }

    /**
     * Get knowledge base statistics.
     *
     * @return array<string, mixed>
     */
    public function getKnowledgeStats(): array
    {
        $byType = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byAudience = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->select('audience', DB::raw('COUNT(*) as count'))
            ->groupBy('audience')
            ->pluck('count', 'audience')
            ->toArray();

        $mostUsed = AILearnedKnowledge::query()
            ->where('enabled', true)
            ->where('use_count', '>', 0)
            ->orderByDesc('use_count')
            ->limit(5)
            ->get(['title', 'use_count', 'type']);

        return [
            'total' => AILearnedKnowledge::where('enabled', true)->count(),
            'by_type' => $byType,
            'by_audience' => $byAudience,
            'most_used' => $mostUsed,
        ];
    }

    /**
     * Get average response times (message count per conversation).
     *
     * @return array<string, mixed>
     */
    public function getAverageResponseTimes(int $days = 30): array
    {
        $conversations = AIConversation::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->withCount('messages')
            ->get();

        if ($conversations->isEmpty()) {
            return [
                'avg_messages_per_conversation' => 0,
                'total_conversations' => 0,
                'total_messages' => 0,
            ];
        }

        $totalMessages = $conversations->sum('messages_count');
        $avgMessages = round($totalMessages / $conversations->count(), 1);

        return [
            'avg_messages_per_conversation' => $avgMessages,
            'total_conversations' => $conversations->count(),
            'total_messages' => $totalMessages,
        ];
    }

    /**
     * Get a summary for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(): array
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $lastWeek = now()->subWeek()->startOfDay();

        return [
            'conversations_today' => AIConversation::where('created_at', '>=', $today)->count(),
            'conversations_yesterday' => AIConversation::whereBetween('created_at', [$yesterday, $today])->count(),
            'conversations_this_week' => AIConversation::where('created_at', '>=', $lastWeek)->count(),
            'open_gaps' => AIKnowledgeGap::open()->count(),
            'frequent_gaps' => AIKnowledgeGap::open()->where('occurrence_count', '>=', 3)->count(),
            'active_knowledge' => AILearnedKnowledge::where('enabled', true)->count(),
            'messages_today' => AIConversationMessage::where('created_at', '>=', $today)->count(),
        ];
    }

    /**
     * Get topic distribution from conversations.
     *
     * The topic taxonomy is host-supplied via config: ai-core is a
     * domain-neutral package and cannot know the consuming app's subject
     * matter (e-commerce, healthcare, etc.), so it is not hardcoded here.
     * Each topic maps to a list of patterns; a topic matches a message if
     * ANY of its patterns matches.
     *
     * @return Collection<int, array{topic: string, count: int}>
     */
    public function getTopicDistribution(int $days = 30): Collection
    {
        /** @var array<string, list<string>> $topicPatterns */
        $topicPatterns = config('ai.topic_patterns', []);

        $messages = AIConversationMessage::query()
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays($days))
            ->pluck('content');

        $topicCounts = array_fill_keys(array_keys($topicPatterns), 0);

        foreach ($messages as $content) {
            foreach ($topicPatterns as $topic => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $content)) {
                        $topicCounts[$topic]++;
                        break 2; // Only count primary topic
                    }
                }
            }
        }

        return collect($topicCounts)
            ->map(fn (int $count, string $topic): array => [
                'topic' => $topic,
                'count' => $count,
            ])
            ->sortByDesc('count')
            ->values();
    }
}
