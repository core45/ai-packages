<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Closure;
use Core45\AiCore\Models\SeoIndexable;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;

class CornerstoneToggle extends Component
{
    protected string $view = 'ai-core::forms.components.cornerstone-toggle';

    protected string|Closure|null $locale = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function locale(string|Closure|null $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->evaluate($this->locale);
    }

    /**
     * Check if the current record is marked as cornerstone content for the given locale.
     */
    public function isCornerstone(): bool
    {
        $locale = $this->getLocale() ?? app()->getLocale();
        $record = $this->getRecord();

        if (!$record instanceof Model || !method_exists($record, 'seoIndexable')) {
            return false;
        }

        /** @var SeoIndexable|null $indexable */
        $indexable = $record->seoIndexable($locale);

        return (bool) $indexable?->is_cornerstone;
    }
}
