<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Filament\Schemas\Components\Component;

class SerpPreview extends Component
{
    protected string $view = 'ai-core::forms.components.serp-preview';

    protected string $titleStatePath = '';

    protected string $descriptionStatePath = '';

    protected string $urlStatePath = '';

    public static function make(): static
    {
        return app(static::class);
    }

    public function titleStatePath(string $path): static
    {
        $this->titleStatePath = $path;

        return $this;
    }

    public function descriptionStatePath(string $path): static
    {
        $this->descriptionStatePath = $path;

        return $this;
    }

    public function urlStatePath(string $path): static
    {
        $this->urlStatePath = $path;

        return $this;
    }

    public function getTitleStatePath(): string
    {
        return $this->titleStatePath;
    }

    public function getDescriptionStatePath(): string
    {
        return $this->descriptionStatePath;
    }

    public function getUrlStatePath(): string
    {
        return $this->urlStatePath;
    }
}
