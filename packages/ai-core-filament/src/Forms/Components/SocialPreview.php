<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Forms\Components;

use Closure;
use Filament\Schemas\Components\Component;

class SocialPreview extends Component
{
    protected string $view = 'ai-core::forms.components.social-preview';

    protected string|Closure|null $ogTitleStatePath = null;

    protected string|Closure|null $ogDescriptionStatePath = null;

    protected string|Closure|null $ogImageUrl = null;

    protected string|Closure|null $twitterTitleStatePath = null;

    protected string|Closure|null $twitterDescriptionStatePath = null;

    protected string|Closure|null $twitterImageUrl = null;

    protected string|Closure|null $siteUrl = null;

    protected string|Closure|null $fallbackTitleStatePath = null;

    protected string|Closure|null $fallbackDescriptionStatePath = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function ogTitleStatePath(string|Closure|null $path): static
    {
        $this->ogTitleStatePath = $path;

        return $this;
    }

    public function getOgTitleStatePath(): ?string
    {
        return $this->evaluate($this->ogTitleStatePath);
    }

    public function ogDescriptionStatePath(string|Closure|null $path): static
    {
        $this->ogDescriptionStatePath = $path;

        return $this;
    }

    public function getOgDescriptionStatePath(): ?string
    {
        return $this->evaluate($this->ogDescriptionStatePath);
    }

    public function ogImageUrl(string|Closure|null $url): static
    {
        $this->ogImageUrl = $url;

        return $this;
    }

    public function getOgImageUrl(): ?string
    {
        return $this->evaluate($this->ogImageUrl);
    }

    public function twitterTitleStatePath(string|Closure|null $path): static
    {
        $this->twitterTitleStatePath = $path;

        return $this;
    }

    public function getTwitterTitleStatePath(): ?string
    {
        return $this->evaluate($this->twitterTitleStatePath);
    }

    public function twitterDescriptionStatePath(string|Closure|null $path): static
    {
        $this->twitterDescriptionStatePath = $path;

        return $this;
    }

    public function getTwitterDescriptionStatePath(): ?string
    {
        return $this->evaluate($this->twitterDescriptionStatePath);
    }

    public function twitterImageUrl(string|Closure|null $url): static
    {
        $this->twitterImageUrl = $url;

        return $this;
    }

    public function getTwitterImageUrl(): ?string
    {
        return $this->evaluate($this->twitterImageUrl);
    }

    public function siteUrl(string|Closure|null $url): static
    {
        $this->siteUrl = $url;

        return $this;
    }

    public function getSiteUrl(): ?string
    {
        return $this->evaluate($this->siteUrl);
    }

    public function fallbackTitleStatePath(string|Closure|null $path): static
    {
        $this->fallbackTitleStatePath = $path;

        return $this;
    }

    public function getFallbackTitleStatePath(): ?string
    {
        return $this->evaluate($this->fallbackTitleStatePath);
    }

    public function fallbackDescriptionStatePath(string|Closure|null $path): static
    {
        $this->fallbackDescriptionStatePath = $path;

        return $this;
    }

    public function getFallbackDescriptionStatePath(): ?string
    {
        return $this->evaluate($this->fallbackDescriptionStatePath);
    }
}
