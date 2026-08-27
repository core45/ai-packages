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
 * analysis-category enum. Category filtering is out of scope for this port,
 * so category filtering compares against the raw 'readability' string value
 * that the analyzer is expected to write into `analysis_issues`.
 */
class ReadabilityAnalysisPanel extends Component
{
    protected string $view = 'ai-core::forms.components.readability-analysis-panel';

    protected int|Closure|null $score = null;

    /** @var array<int, array<string, mixed>>|Closure|null */
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
     * @param  array<int, array<string, mixed>>|Closure|null  $issues
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

    /**
     * Create a ReadabilityAnalysisPanel from an SeoIndexable model.
     */
    public static function fromIndexable(SeoIndexable $indexable): static
    {
        return static::make()
            ->score($indexable->readability_score)
            ->issues($indexable->analysis_issues ?? [])
            ->analyzedAt($indexable->analyzed_at)
            ->overallStatus($indexable->overall_status);
    }

    public function getScore(): ?int
    {
        return $this->evaluate($this->score) ?? $this->resolveIndexable()?->readability_score;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIssues(): array
    {
        /** @var array<string, mixed>|null $issues */
        $issues = $this->evaluate($this->issues) ?? $this->resolveIndexable()?->analysis_issues;

        return $issues ?? [];
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
     * Get only readability-category issues from the issues array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReadabilityIssues(): array
    {
        $allIssues = $this->getIssues();
        $primaryIssues = $allIssues['primary'] ?? [];

        return array_values(array_filter(
            $primaryIssues,
            fn (array $issue): bool => ($issue['category'] ?? '') === 'readability',
        ));
    }

    /**
     * Returns readability issues grouped by severity.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getGroupedReadabilityIssues(): array
    {
        $groups = [
            'error' => [],
            'warning' => [],
            'ok' => [],
            'good' => [],
        ];

        foreach ($this->getReadabilityIssues() as $issue) {
            $severity = $issue['severity'] ?? 'ok';

            if (isset($groups[$severity])) {
                $groups[$severity][] = $issue;
            }
        }

        return $groups;
    }

    public function getPassedCount(): int
    {
        return count(array_filter(
            $this->getReadabilityIssues(),
            fn (array $issue): bool => ($issue['severity'] ?? '') === 'good',
        ));
    }

    public function getFailedCount(): int
    {
        return count(array_filter(
            $this->getReadabilityIssues(),
            fn (array $issue): bool => ($issue['severity'] ?? '') !== 'good',
        ));
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
}
