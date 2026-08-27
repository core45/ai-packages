<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Closure;
use Core45\AiCore\Models\SeoIndexable;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NOTE: The source implementation filtered issues with a host-owned
 * analysis-category enum and exposed a `hasFixableIssues()` helper backed
 * by a host-owned SEO improvement service. Neither category filtering nor
 * the "Improve SEO" action are in scope for this port, so
 * category filtering here compares against the raw string values
 * ('seo', 'external') that the analyzer is expected to write into
 * `analysis_issues`, and `hasFixableIssues()`/the "Improve SEO" action have
 * been dropped along with their view markup.
 */
class SeoAnalysisPanel extends Component
{
    protected string $view = 'ai-core::forms.components.seo-analysis-panel';

    protected int|Closure|null $score = null;

    /** @var array<string|int, mixed>|Closure|null */
    protected array|Closure|null $issues = null;

    protected Carbon|string|Closure|null $analyzedAt = null;

    protected string|Closure|null $overallStatus = null;

    protected ?string $locale = null;

    private ?SeoIndexable $resolvedIndexable = null;

    private bool $indexableResolved = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public function locale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Resolve the SeoIndexable from the record for the configured locale.
     */
    private function resolveIndexable(): ?SeoIndexable
    {
        if ($this->indexableResolved) {
            return $this->resolvedIndexable;
        }

        $this->indexableResolved = true;

        if ($this->locale === null) {
            return null;
        }

        $record = $this->getRecord();

        if (!$record instanceof Model || !method_exists($record, 'seoIndexable')) {
            return null;
        }

        $this->resolvedIndexable = $record->seoIndexable($this->locale);

        return $this->resolvedIndexable;
    }

    public function score(int|Closure|null $score): static
    {
        $this->score = $score;

        return $this;
    }

    /**
     * @param  array<string|int, mixed>|Closure|null  $issues
     */
    public function issues(array|Closure|null $issues): static
    {
        $this->issues = $issues;

        return $this;
    }

    public function analyzedAt(Carbon|string|Closure|null $analyzedAt): static
    {
        $this->analyzedAt = $analyzedAt;

        return $this;
    }

    public function overallStatus(string|Closure|null $status): static
    {
        $this->overallStatus = $status;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->evaluate($this->score) ?? $this->resolveIndexable()?->seo_score;
    }

    /**
     * @return array<string|int, mixed>|null
     */
    public function getIssues(): ?array
    {
        return $this->evaluate($this->issues) ?? $this->resolveIndexable()?->analysis_issues;
    }

    public function getAnalyzedAt(): Carbon|string|null
    {
        $value = $this->evaluate($this->analyzedAt) ?? $this->resolveIndexable()?->analyzed_at;

        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    public function getOverallStatus(): ?string
    {
        return $this->evaluate($this->overallStatus) ?? $this->resolveIndexable()?->overall_status;
    }

    /**
     * Get issues filtered to only the SEO category, deduplicated by message.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSeoIssues(): array
    {
        $issues = $this->getIssues();

        if ($issues === null) {
            return [];
        }

        $primaryIssues = $issues['primary'] ?? [];

        $filtered = array_filter(
            $primaryIssues,
            fn (array $issue): bool => ($issue['category'] ?? '') === 'seo',
        );

        $seen = [];
        $unique = [];

        foreach ($filtered as $issue) {
            $message = $issue['message'] ?? '';

            if (!isset($seen[$message])) {
                $seen[$message] = true;
                $unique[] = $issue;
            }
        }

        return $unique;
    }

    /**
     * Get SEO issues grouped by severity.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getGroupedSeoIssues(): array
    {
        $groups = [
            'error' => [],
            'warning' => [],
            'ok' => [],
            'good' => [],
        ];

        foreach ($this->getSeoIssues() as $issue) {
            $severity = $issue['severity'] ?? 'ok';

            if (isset($groups[$severity])) {
                $groups[$severity][] = $issue;
            }
        }

        return $groups;
    }

    /**
     * Get the score color based on the score value.
     */
    public function getScoreColor(): string
    {
        $score = $this->getScore();

        if ($score === null) {
            return '#9ca3af';
        }

        if ($score >= 70) {
            return '#22c55e';
        }

        if ($score >= 40) {
            return '#f59e0b';
        }

        return '#ef4444';
    }

    /**
     * Get issues filtered to only the External category.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExternalIssues(): array
    {
        $issues = $this->getIssues();

        if ($issues === null) {
            return [];
        }

        return array_values($issues['external'] ?? []);
    }

    /**
     * Get external issues grouped by severity.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getGroupedExternalIssues(): array
    {
        $groups = [
            'error' => [],
            'warning' => [],
            'ok' => [],
            'good' => [],
        ];

        foreach ($this->getExternalIssues() as $issue) {
            $severity = $issue['severity'] ?? 'ok';

            if (isset($groups[$severity])) {
                $groups[$severity][] = $issue;
            }
        }

        return $groups;
    }

    /**
     * Create a configured instance from an SeoIndexable model.
     */
    public static function fromIndexable(SeoIndexable $indexable): static
    {
        return static::make()
            ->score($indexable->seo_score)
            ->issues($indexable->analysis_issues)
            ->analyzedAt($indexable->analyzed_at)
            ->overallStatus($indexable->overall_status);
    }
}
