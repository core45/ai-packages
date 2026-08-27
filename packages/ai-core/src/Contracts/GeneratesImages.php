<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

interface GeneratesImages
{
    /**
     * Generate an image and return its binary content.
     *
     * @param  array<string, mixed>  $params  Provider-specific parameters (aspect_ratio, etc.)
     * @return array{bytes: string, mime_type: string}
     *
     * @throws \RuntimeException
     */
    public function generate(string $prompt, array $params = []): array;

    /**
     * Get the provider identifier (e.g. 'fal-ai', 'gemini').
     */
    public function providerName(): string;

    /**
     * Get available image model options for form dropdown.
     *
     * @return array<string, string>
     */
    public static function getImageModelOptions(): array;

    /**
     * Get available aspect ratio options for form dropdown.
     *
     * @return array<string, string>
     */
    public static function getAspectRatioOptions(): array;

    /**
     * Whether this provider supports per-image cost estimation.
     */
    public static function supportsCostEstimate(): bool;

    /**
     * The model id this provider generates with when the caller does not
     * pick one explicitly.
     *
     * Lives on the contract so callers never have to switch on
     * `config('image-generation.provider')` themselves — the container
     * binding in `AiCoreServiceProvider::register()` is the single place
     * that maps the configured provider onto a concrete implementation.
     */
    public static function defaultImageModel(): string;

    /**
     * Whether this provider has the credentials it needs to generate images.
     *
     * Same rationale as {@see self::defaultImageModel()}: keeps the
     * "is image generation available?" question free of a second provider
     * switch.
     */
    public static function isConfigured(): bool;
}
