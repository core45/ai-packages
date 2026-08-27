<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Enums\AIConflictStatus;
use Core45\AiCore\Enums\AIConflictType;
use Core45\AiCore\Enums\AIKnowledgeType;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Models\AIKnowledgeConflict;
use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KnowledgeService
{
    use LogsAIOperations;

    /**
     * Optional factory used to resolve the knowledge surface's AI client so the
     * `AI_SURFACE_KNOWLEDGE_DRIVER` override is honored. When null (e.g. direct
     * `new KnowledgeService()` construction in tests) the service always uses
     * the in-process local path.
     */
    protected ?AiClientFactory $factory;

    /**
     * Cache TTL for critical knowledge (1 hour).
     */
    protected const CRITICAL_KNOWLEDGE_CACHE_TTL = 3600;

    public function __construct(?AiClientFactory $factory = null)
    {
        $this->factory = $factory;
    }

    /**
     * Get critical (Tier 1) knowledge for system prompt injection.
     * These are always included in the prompt.
     * Results are cached for 1 hour to reduce database load at scale.
     *
     * @return Collection<int, AILearnedKnowledge>
     */
    public function getCriticalKnowledge(string $audience = 'admin'): Collection
    {
        $this->logDebug('KnowledgeService - Getting Critical Knowledge', [
            'audience' => $audience,
        ]);

        $cacheKey = "ai_critical_knowledge:{$audience}";

        /** @var Collection<int, AILearnedKnowledge> $knowledge */
        $knowledge = Cache::remember(
            $cacheKey,
            self::CRITICAL_KNOWLEDGE_CACHE_TTL,
            function () use ($audience): Collection {
                return AILearnedKnowledge::query()
                    ->enabled()
                    ->critical()
                    ->forAudience($audience)
                    ->orderBy('type')
                    ->get();
            }
        );

        $this->logDebug('KnowledgeService - Critical Knowledge Retrieved', [
            'audience' => $audience,
            'count' => $knowledge->count(),
            'entries' => $knowledge->pluck('title', 'id')->toArray(),
        ]);

        return $knowledge;
    }

    /**
     * Clear critical knowledge cache for all audiences.
     * Call this when knowledge entries are created, updated, or deleted.
     */
    public function clearCriticalKnowledgeCache(): void
    {
        $audiences = ['admin', 'customer', 'public'];

        foreach ($audiences as $audience) {
            Cache::forget("ai_critical_knowledge:{$audience}");
        }

        $this->logDebug('KnowledgeService - Critical Knowledge Cache Cleared');
    }

    /**
     * Search contextual (Tier 2) knowledge based on user message.
     * Keyword search over learned knowledge.
     *
     * @return Collection<int, AILearnedKnowledge>
     */
    public function searchContextualKnowledge(string $query, string $audience = 'admin', int $limit = 5): Collection
    {
        if (empty(trim($query))) {
            $this->logDebug('KnowledgeService - Search Skipped (empty query)');

            return collect();
        }

        return $this->searchContextualKnowledgeKeyword($query, $audience, $limit);
    }

    /**
     * Search contextual knowledge using keyword search (fallback).
     *
     * @return Collection<int, AILearnedKnowledge>
     */
    private function searchContextualKnowledgeKeyword(string $query, string $audience = 'admin', int $limit = 5): Collection
    {
        $this->logDebug('KnowledgeService - Searching Contextual Knowledge (Keyword)', [
            'query' => $query,
            'audience' => $audience,
            'limit' => $limit,
        ]);

        // Use Scout/Meilisearch for full-text search. Learned knowledge is a prompt
        // enhancement — a missing/unavailable search index (e.g. Meilisearch 404 on a
        // fresh server before scout:import) must degrade to no context, not crash the
        // caller (blog pipeline modules, AI chat).
        try {
            $knowledge = AILearnedKnowledge::search($query)
                ->query(function ($builder) use ($audience) {
                    $builder->enabled()
                        ->contextual()
                        ->forAudience($audience);
                })
                ->take($limit)
                ->get();
        } catch (\Exception $e) {
            Log::warning('KnowledgeService keyword search failed, returning no contextual knowledge', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        $this->logDebug('KnowledgeService - Contextual Knowledge Found (Keyword)', [
            'query' => $query,
            'count' => $knowledge->count(),
            'entries' => $knowledge->pluck('title', 'id')->toArray(),
        ]);

        return $knowledge;
    }

    /**
     * Format knowledge entries for injection into system prompt.
     *
     * @param  Collection<int, AILearnedKnowledge>  $knowledge
     */
    public function formatForPrompt(Collection $knowledge): string
    {
        if ($knowledge->isEmpty()) {
            return '';
        }

        $sections = [
            'forbidden' => [],
            'required' => [],
            'instruction' => [],
            'fact' => [],
            'faq' => [],
        ];

        foreach ($knowledge as $entry) {
            $sections[$entry->type->value][] = $entry;
        }

        $output = [];

        // Forbidden behaviors come first - most important constraints
        if (!empty($sections['forbidden'])) {
            $output[] = 'FORBIDDEN - You must NEVER do these things:';
            foreach ($sections['forbidden'] as $entry) {
                $output[] = "- NEVER: {$entry->content}";
            }
        }

        // Required behaviors - things AI must always do
        if (!empty($sections['required'])) {
            $output[] = "\nREQUIRED - You MUST always do these things:";
            foreach ($sections['required'] as $entry) {
                $output[] = "- ALWAYS: {$entry->content}";
            }
        }

        if (!empty($sections['instruction'])) {
            $output[] = "\nCUSTOM INSTRUCTIONS:";
            foreach ($sections['instruction'] as $entry) {
                $output[] = "- {$entry->content}";
            }
        }

        if (!empty($sections['fact'])) {
            $output[] = "\nBUSINESS INFORMATION:";
            foreach ($sections['fact'] as $entry) {
                $output[] = "- {$entry->title}: {$entry->content}";
            }
        }

        if (!empty($sections['faq'])) {
            $output[] = "\nFREQUENTLY ASKED QUESTIONS:";
            foreach ($sections['faq'] as $entry) {
                $output[] = "Q: {$entry->title}";
                $output[] = "A: {$entry->content}";
            }
        }

        return implode("\n", $output);
    }

    /**
     * Get all knowledge for a specific audience, formatted for the prompt.
     */
    public function getKnowledgeForPrompt(string $audience = 'admin', ?string $userMessage = null): string
    {
        $this->logDebug('KnowledgeService - Getting Knowledge for Prompt', [
            'audience' => $audience,
            'has_user_message' => $userMessage !== null,
        ]);

        // Get critical knowledge (always included)
        $critical = $this->getCriticalKnowledge($audience);

        // Get contextual knowledge (based on user message)
        $contextual = collect();
        if ($userMessage) {
            $contextual = $this->searchContextualKnowledge($userMessage, $audience);
        }

        // Merge and deduplicate
        $all = $critical->merge($contextual)->unique('id');

        // Record usage (batch update to avoid N+1 queries)
        $ids = $all->pluck('id')->toArray();
        if (!empty($ids)) {
            AILearnedKnowledge::whereIn('id', $ids)->increment('use_count');
            AILearnedKnowledge::whereIn('id', $ids)->update(['last_used_at' => now()]);
        }

        $formattedKnowledge = $this->formatForPrompt($all);

        // Log knowledge injection details
        $this->logKnowledgeInjection(
            'KnowledgeService',
            $critical->count(),
            $contextual->count(),
            $formattedKnowledge
        );

        return $formattedKnowledge;
    }

    /**
     * Create a new knowledge entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AILearnedKnowledge
    {
        // Validate input to prevent security issues
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(AIKnowledgeType::class)],
            'tier' => 'required|in:critical,contextual',
            'audience' => 'required|in:public,customer,admin',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:65535',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:100',
            'source_type' => 'nullable|in:manual,suggested',
            'enabled' => 'boolean',
        ])->validate();

        $this->logDebug('KnowledgeService - Creating Knowledge Entry', [
            'type' => $validated['type'],
            'title' => $validated['title'],
            'audience' => $validated['audience'],
            'tier' => $validated['tier'],
        ]);

        $validated['created_by'] = Auth::id();
        $validated['source_type'] = $validated['source_type'] ?? 'manual';

        $knowledge = AILearnedKnowledge::create($validated);

        // Clear cache if this could affect critical knowledge
        if ($validated['tier'] === 'critical') {
            $this->clearCriticalKnowledgeCache();
        }

        // Detect conflicts with existing knowledge entries
        $this->detectConflicts($knowledge);

        $this->logDebug('KnowledgeService - Knowledge Entry Created', [
            'id' => $knowledge->id,
            'type' => $knowledge->type,
            'title' => $knowledge->title,
        ]);

        return $knowledge;
    }

    /**
     * Update a knowledge entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AILearnedKnowledge $knowledge, array $data): AILearnedKnowledge
    {
        // Validate input to prevent security issues
        $validated = Validator::make($data, [
            'type' => ['sometimes', Rule::enum(AIKnowledgeType::class)],
            'tier' => 'sometimes|in:critical,contextual',
            'audience' => 'sometimes|in:public,customer,admin',
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:65535',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:100',
            'source_type' => 'sometimes|in:manual,suggested',
            'enabled' => 'boolean',
        ])->validate();

        $wasCritical = $knowledge->tier === 'critical';
        $willBeCritical = ($validated['tier'] ?? $knowledge->tier) === 'critical';

        $this->logDebug('KnowledgeService - Updating Knowledge Entry', [
            'id' => $knowledge->id,
            'fields_updated' => array_keys($validated),
        ]);

        $knowledge->update($validated);

        // Clear cache if critical tier is involved (was or will be)
        if ($wasCritical || $willBeCritical) {
            $this->clearCriticalKnowledgeCache();
        }

        $freshKnowledge = $knowledge->fresh() ?? $knowledge;

        // Re-check conflicts after update (keywords or type may have changed)
        $this->detectConflicts($freshKnowledge);

        $this->logDebug('KnowledgeService - Knowledge Entry Updated', [
            'id' => $freshKnowledge->id,
        ]);

        return $freshKnowledge;
    }

    /**
     * Delete a knowledge entry.
     */
    public function delete(AILearnedKnowledge $knowledge): bool
    {
        $wasCritical = $knowledge->tier === 'critical';

        $this->logDebug('KnowledgeService - Deleting Knowledge Entry', [
            'id' => $knowledge->id,
            'title' => $knowledge->title,
        ]);

        $deleted = $knowledge->delete();

        // Clear cache if this was critical knowledge
        if ($wasCritical) {
            $this->clearCriticalKnowledgeCache();
        }

        return (bool) $deleted;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conflict Detection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Minimum keyword overlap to flag a potential conflict.
     */
    protected const CONFLICT_KEYWORD_THRESHOLD = 3;

    /**
     * Type pairs that are considered contradictory.
     *
     * @var array<int, array{0: AIKnowledgeType, 1: AIKnowledgeType}>
     */
    protected const CONTRADICTORY_TYPE_PAIRS = [
        [AIKnowledgeType::Forbidden, AIKnowledgeType::Required],
        [AIKnowledgeType::Forbidden, AIKnowledgeType::Instruction],
    ];

    /**
     * Detect conflicts between a knowledge entry and all other enabled entries.
     * Creates AIKnowledgeConflict records for any detected conflicts.
     *
     * @return Collection<int, AIKnowledgeConflict>
     */
    public function detectConflicts(AILearnedKnowledge $entry): Collection
    {
        if (!$entry->enabled || empty($entry->keywords)) {
            return collect();
        }

        $entryKeywords = collect($entry->keywords)->map(fn (string $kw) => mb_strtolower($kw));
        $conflicts = collect();

        // Get all other enabled entries that have keywords
        $candidates = AILearnedKnowledge::query()
            ->enabled()
            ->where('id', '!=', $entry->id)
            ->whereNotNull('keywords')
            ->get();

        foreach ($candidates as $candidate) {
            $candidateKeywords = collect($candidate->keywords)->map(fn (string $kw) => mb_strtolower($kw));
            $overlap = $entryKeywords->intersect($candidateKeywords)->values();

            if ($overlap->count() < self::CONFLICT_KEYWORD_THRESHOLD) {
                continue;
            }

            $conflictType = $this->determineConflictType($entry, $candidate);
            if ($conflictType === null) {
                continue;
            }

            // Normalize IDs so (A,B) and (B,A) are the same record
            [$idA, $idB] = $entry->id < $candidate->id
                ? [$entry->id, $candidate->id]
                : [$candidate->id, $entry->id];

            $totalKeywords = $entryKeywords->merge($candidateKeywords)->unique()->count();
            $overlapScore = $totalKeywords > 0
                ? round(($overlap->count() / $totalKeywords) * 100, 2)
                : 0;

            $conflict = AIKnowledgeConflict::updateOrCreate(
                [
                    'knowledge_a_id' => $idA,
                    'knowledge_b_id' => $idB,
                ],
                [
                    'conflict_type' => $conflictType,
                    'overlap_keywords' => $overlap->all(),
                    'overlap_score' => $overlapScore,
                    'status' => AIConflictStatus::Detected,
                ]
            );

            $conflicts->push($conflict);
        }

        if ($conflicts->isNotEmpty()) {
            $this->logDebug('KnowledgeService - Conflicts Detected', [
                'entry_id' => $entry->id,
                'conflicts_found' => $conflicts->count(),
            ]);
        }

        return $conflicts;
    }

    /**
     * Run conflict detection across all enabled knowledge entries.
     * Returns the total number of unique conflicts found.
     */
    public function detectAllConflicts(): int
    {
        // CUT-01 (10-05): when the knowledge surface is flipped to remote, route
        // conflict detection to phasea's api/ai/knowledge/conflicts via
        // AiClientFactory::for(AiSurface::Knowledge). Under local (or an unset
        // override) the original in-process keyword-overlap scan runs unchanged,
        // so behavior is identical on rollback.
        if ($this->factory !== null) {
            $client = $this->factory->for(AiSurface::Knowledge);

            if ($client instanceof RemoteAiClient) {
                return $this->detectAllConflictsRemote($client);
            }
        }

        // Clear existing detected (non-resolved) conflicts before re-scanning
        AIKnowledgeConflict::query()
            ->ofStatus(AIConflictStatus::Detected)
            ->delete();

        $entries = AILearnedKnowledge::query()
            ->enabled()
            ->whereNotNull('keywords')
            ->get();

        foreach ($entries as $entry) {
            $this->detectConflicts($entry);
        }

        // Count unique conflicts from the database (updateOrCreate deduplicates pairs)
        $totalConflicts = AIKnowledgeConflict::query()
            ->ofStatus(AIConflictStatus::Detected)
            ->count();

        $this->logDebug('KnowledgeService - Full Conflict Scan Complete', [
            'entries_scanned' => $entries->count(),
            'conflicts_found' => $totalConflicts,
        ]);

        return $totalConflicts;
    }

    /**
     * Replace the locally-detected conflict set with phasea's AI-computed set.
     *
     * Mirrors the local `detectAllConflicts()` contract: returns the count of
     * unique detected conflicts. Host-owned knowledge DATA stays local (D-B8-6) —
     * only the conflict-generation call is routed to the remote service.
     *
     * Safety contract (audit D1):
     *  - Transport/parse failures throw from the client BEFORE any local
     *    mutation — the existing `AIKnowledgeConflict` rows are preserved.
     *  - A VALID response (including a legitimately-empty list) swaps the
     *    Detected set atomically inside one DB transaction, so a mid-swap
     *    failure can never leave the table wiped.
     *  - phasea computes conflicts over its OWN `LearnedKnowledge` rows and
     *    returns UUID-string ids; a row is persisted only when both ids map
     *    onto existing host knowledge rows — unmappable rows are skipped
     *    without failing the batch.
     */
    private function detectAllConflictsRemote(RemoteAiClient $client): int
    {
        $conflicts = $client->detectKnowledgeConflicts([], (string) Str::uuid());

        $mappedConflicts = [];
        $skipped = 0;

        foreach ($conflicts as $conflict) {
            $knowledgeAId = $this->mapRemoteKnowledgeId($conflict['knowledge_a_id'] ?? null);
            $knowledgeBId = $this->mapRemoteKnowledgeId($conflict['knowledge_b_id'] ?? null);

            if ($knowledgeAId === null || $knowledgeBId === null) {
                $skipped++;

                continue;
            }

            $mappedConflicts[] = [
                'knowledge_a_id' => $knowledgeAId,
                'knowledge_b_id' => $knowledgeBId,
                'conflict_type' => $this->mapRemoteConflictType($conflict['conflict_type'] ?? null),
                'overlap_keywords' => $conflict['overlap_keywords'] ?? [],
                'overlap_score' => $conflict['overlap_score'] ?? 0,
            ];
        }

        // Atomic delete+insert swap — the response is valid at this point.
        DB::transaction(function () use ($mappedConflicts): void {
            AIKnowledgeConflict::query()
                ->ofStatus(AIConflictStatus::Detected)
                ->delete();

            foreach ($mappedConflicts as $conflict) {
                AIKnowledgeConflict::updateOrCreate(
                    [
                        'knowledge_a_id' => $conflict['knowledge_a_id'],
                        'knowledge_b_id' => $conflict['knowledge_b_id'],
                    ],
                    [
                        'conflict_type' => $conflict['conflict_type'],
                        'overlap_keywords' => $conflict['overlap_keywords'],
                        'overlap_score' => $conflict['overlap_score'],
                        'status' => AIConflictStatus::Detected,
                    ]
                );
            }
        });

        $totalConflicts = AIKnowledgeConflict::query()
            ->ofStatus(AIConflictStatus::Detected)
            ->count();

        $this->logDebug('KnowledgeService - Remote Conflict Scan Complete', [
            'conflicts_found' => $totalConflicts,
            'unmappable_rows_skipped' => $skipped,
        ]);

        return $totalConflicts;
    }

    /**
     * Map a remote conflict knowledge id onto a host `AILearnedKnowledge` PK.
     *
     * Host knowledge PKs are integers; phasea's conflict rows carry UUID
     * strings referencing phasea's own `LearnedKnowledge`. Integer-shaped ids
     * (int or digit-string) that resolve to an existing host row map; UUIDs
     * and anything else are unmappable (null) until a host↔phasea knowledge
     * id sync exists (audit D1 authority decision, recorded in the plan).
     */
    private function mapRemoteKnowledgeId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            return null;
        }

        return AILearnedKnowledge::query()->whereKey($value)->exists() ? $value : null;
    }

    /**
     * Normalize a remote conflict-type value (int or slug string) to the local
     * int-backed `AIConflictType`. Unknown values default to PartialOverlap.
     */
    private function mapRemoteConflictType(mixed $value): AIConflictType
    {
        if ($value instanceof AIConflictType) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            try {
                return AIConflictType::from((int) $value);
            } catch (\Throwable) {
                // fall through to slug mapping
            }
        }

        return match (is_string($value) ? strtolower($value) : '') {
            'direct_contradiction', 'directcontradiction' => AIConflictType::DirectContradiction,
            'ambiguous' => AIConflictType::Ambiguous,
            default => AIConflictType::PartialOverlap,
        };
    }

    /**
     * Determine the conflict type between two knowledge entries.
     * Returns null if the entries are not in contradiction.
     */
    protected function determineConflictType(AILearnedKnowledge $a, AILearnedKnowledge $b): ?AIConflictType
    {
        foreach (self::CONTRADICTORY_TYPE_PAIRS as [$typeX, $typeY]) {
            if (
                ($a->type === $typeX && $b->type === $typeY)
                || ($a->type === $typeY && $b->type === $typeX)
            ) {
                return AIConflictType::DirectContradiction;
            }
        }

        // Same type but same keywords = potential ambiguity (duplicate/conflicting facts)
        if ($a->type === $b->type && in_array($a->type, [AIKnowledgeType::Fact, AIKnowledgeType::Instruction])) {
            return AIConflictType::Ambiguous;
        }

        // Different non-contradictory types with significant keyword overlap
        return AIConflictType::PartialOverlap;
    }

    /**
     * Get statistics about knowledge entries.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $total = AILearnedKnowledge::count();
        $enabled = AILearnedKnowledge::enabled()->count();

        return [
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $total - $enabled,
            'by_type' => [
                'instruction' => AILearnedKnowledge::ofType(AIKnowledgeType::Instruction)->count(),
                'fact' => AILearnedKnowledge::ofType(AIKnowledgeType::Fact)->count(),
                'faq' => AILearnedKnowledge::ofType(AIKnowledgeType::Faq)->count(),
                'forbidden' => AILearnedKnowledge::ofType(AIKnowledgeType::Forbidden)->count(),
                'required' => AILearnedKnowledge::ofType(AIKnowledgeType::Required)->count(),
            ],
            'by_tier' => [
                'critical' => AILearnedKnowledge::critical()->count(),
                'contextual' => AILearnedKnowledge::contextual()->count(),
            ],
            'by_audience' => [
                'public' => AILearnedKnowledge::where('audience', 'public')->count(),
                'customer' => AILearnedKnowledge::where('audience', 'customer')->count(),
                'admin' => AILearnedKnowledge::where('audience', 'admin')->count(),
            ],
        ];
    }
}
