<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Services\KnowledgeService;
use Core45\AiCore\Support\PromptKeyRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI Prompt Override - database-stored prompt modifications.
 *
 * @property int $id
 * @property string $prompt_key
 * @property string $override_content
 * @property string $change_type
 * @property int|null $suggestion_id
 * @property bool $enabled
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AIImprovementSuggestion|null $suggestion
 */
class AIPromptOverride extends Model
{
    protected $table = 'ai_prompt_overrides';

    protected $fillable = [
        'prompt_key',
        'override_content',
        'change_type',
        'suggestion_id',
        'enabled',
        'description',
    ];

    /**
     * OPS-02 (10-05): prompt-override edits are hot-path inputs to the system
     * prompt assembled from critical knowledge. Invalidate the critical-knowledge
     * cache on every save/delete so an override change is reflected on the next
     * remote call without waiting for the 1h TTL. The cache key is per-audience
     * (not per-tenant); overrides are global, so all audience keys are cleared
     * via the existing `KnowledgeService::clearCriticalKnowledgeCache()`.
     */
    protected static function booted(): void
    {
        static::saved(function (): void {
            app(KnowledgeService::class)->clearCriticalKnowledgeCache();
        });

        static::deleted(function (): void {
            app(KnowledgeService::class)->clearCriticalKnowledgeCache();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    // Relationships

    /**
     * @return BelongsTo<AIImprovementSuggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AIImprovementSuggestion::class, 'suggestion_id');
    }

    // Scopes

    /**
     * @param  Builder<AIPromptOverride>  $query
     * @return Builder<AIPromptOverride>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<AIPromptOverride>  $query
     * @return Builder<AIPromptOverride>
     */
    public function scopeForPrompt(Builder $query, string $promptKey): Builder
    {
        return $query->where('prompt_key', $promptKey);
    }

    // Static Methods

    /**
     * Get all enabled overrides for a specific prompt key.
     *
     * @return Collection<int, self>
     */
    public static function getOverridesFor(string $promptKey): Collection
    {
        /** @var Collection<int, self> */
        return static::query()
            ->forPrompt($promptKey)
            ->enabled()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Apply all overrides to a base prompt.
     */
    public static function applyOverrides(string $promptKey, string $basePrompt): string
    {
        $overrides = static::getOverridesFor($promptKey);

        foreach ($overrides as $override) {
            $basePrompt = $override->applyTo($basePrompt);
        }

        return $basePrompt;
    }

    /**
     * Apply this override to a prompt string.
     */
    public function applyTo(string $prompt): string
    {
        return match ($this->change_type) {
            'append' => $prompt."\n\n".$this->override_content,
            'prepend' => $this->override_content."\n\n".$prompt,
            'replace_section' => $this->replaceSection($prompt),
            default => $prompt."\n\n".$this->override_content,
        };
    }

    /**
     * Replace a section in the prompt (marked by comments).
     */
    protected function replaceSection(string $prompt): string
    {
        // Look for section markers like: <!-- SECTION: pricing -->...<!-- /SECTION: pricing -->
        $pattern = '/<!--\s*SECTION:\s*'.preg_quote($this->prompt_key, '/').'\s*-->.*?<!--\s*\/SECTION:\s*'.preg_quote($this->prompt_key, '/').'\s*-->/s';

        if (preg_match($pattern, $prompt)) {
            return preg_replace(
                $pattern,
                "<!-- SECTION: {$this->prompt_key} -->\n{$this->override_content}\n<!-- /SECTION: {$this->prompt_key} -->",
                $prompt
            ) ?? $prompt;
        }

        // If section doesn't exist, append
        return $prompt."\n\n".$this->override_content;
    }

    /**
     * Every prompt key any installed package has registered.
     *
     * This method used to enumerate the whole taxonomy inline, including keys
     * owned by `ai-products` and `ai-webinars` — the §2.5 item 3 leak. It now
     * asks the registry, which each package populates from its own provider
     * (plan §9 D14). `ai-core` names only the seven keys it owns.
     *
     * An application with only `ai-core` installed therefore sees seven keys
     * instead of nineteen, which is the point: the missing twelve had no code
     * behind them there.
     *
     * @return array<string, string>
     */
    public static function availablePromptKeys(): array
    {
        return app(PromptKeyRegistry::class)->all();
    }

    /**
     * Filament badge colour for a prompt key, grouped by the audience the key
     * belongs to (admin / customer / content).
     *
     * The `match` this used to be named `product_rewrite_system` directly, so
     * even the colour table leaked a domain. The colour now travels with the
     * key, supplied by whichever package registered it, and an unregistered
     * key resolves to `gray` rather than throwing — override rows outlive the
     * package that registered their key.
     *
     * PURE PRESENTATION, DELIBERATELY HOST-SIDE (plan 48-20). It moved here from
     * `App\Services\AI\Prompts\PromptExamples::getCategoryColor()` when that
     * file — the second host prompt library — was deleted and its instruction
     * text went to phasea. A badge colour contains no instruction, is meaningless
     * outside this Filament table, and is not something the prompt owner should
     * be asked to serve; splitting it out is what let the rest of that file go.
     */
    public static function promptKeyBadgeColor(string $promptKey): string
    {
        return app(PromptKeyRegistry::class)->colorFor($promptKey);
    }
}
