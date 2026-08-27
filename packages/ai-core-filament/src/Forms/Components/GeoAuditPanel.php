<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Filament\Schemas\Components\Component;

class GeoAuditPanel extends Component
{
    protected string $view = 'ai-core::forms.components.geo-audit-panel';

    protected ?string $locale = null;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->columnSpanFull();
    }
}
