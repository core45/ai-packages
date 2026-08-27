<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D10 (ai-package extraction, Phase 0): rewrite persisted polymorphic FQCNs
 * to morph aliases for the classes that MOVE into package namespaces.
 *
 * Scope note (deliberately NARROW): only AI-OWNED models that leave
 * `App\Models` need aliases — `AiDetection` / `AiHumanization`, referenced by
 * `ai_operation_continuations.operation_type`. Host domain models (Post,
 * Product, …) stay in `App\Models` forever (plan §3/D7), so their FQCN values
 * in `ai_detections.detectable_type`, `ai_humanizations.humanizable_type`,
 * `ai_content_batches.batchable_type` and `ai_conversations.model_type`
 * remain resolvable without aliasing — and a global morphMap would silently
 * rewrite polymorphic writes in UNRELATED tables (seo_indexables,
 * slug_histories, indexnow_submissions), which a first attempt proved.
 *
 * Idempotent: rows already carrying an alias are left untouched.
 */
return new class extends Migration
{
    /**
     * Polymorphic column => alias => every FQCN that alias has ever been
     * persisted as. Aliases MUST match the map registered in
     * AppServiceProvider::registerAiMorphMap().
     *
     * BOTH namespaces are listed on purpose. Rows written BEFORE this
     * extraction hold the pre-move `App\Models\…` name — that is the value
     * this migration actually exists to rescue, and omitting it would leave
     * every historical row orphaned. Rows written between the class move and
     * this normalization hold the post-move `Core45\AiCore\Models\…` name.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const COLUMN_MAPS = [
        'ai_operation_continuations.operation_type' => [
            'ai_detection' => [
                'App\Models\AiDetection',
                'Core45\AiCore\Models\AiDetection',
            ],
            'ai_humanization' => [
                'App\Models\AiHumanization',
                'Core45\AiCore\Models\AiHumanization',
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMN_MAPS as $reference => $aliases) {
            [$table, $column] = explode('.', $reference);

            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach ($aliases as $alias => $legacyFqcns) {
                DB::table($table)
                    ->whereIn($column, $legacyFqcns)
                    ->update([$column => $alias]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible normalization: re-expanding aliases back to FQCNs would
        // reintroduce the exact namespace-move hazard this migration removes.
        //
        // However, the morph map itself is NOT part of this migration — it lives
        // in AppServiceProvider and stays permanently — so leaving the aliased
        // values in place on rollback keeps every relation resolvable.
    }
};
