<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\ImageGeneration;

use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Support\Facades\Http;

class GeminiImageService implements GeneratesImages
{
    use LogsAIOperations;

    private readonly string $apiKey;

    private readonly string $model;

    private readonly int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('image-generation.gemini.api_key', '');
        $this->model = (string) config('image-generation.gemini.model', 'gemini-2.5-flash-image');
        $this->timeout = (int) config('image-generation.gemini.timeout', 120);
    }

    public function generate(string $prompt, array $params = []): array
    {
        $aspectRatio = (string) ($params['aspect_ratio'] ?? '1:1');
        $model = (string) ($params['model'] ?? $this->model);

        $this->logDebug('GeminiImageService - generate', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'aspect_ratio' => $aspectRatio,
        ]);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Gemini API key is not configured');
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                    'imageConfig' => [
                        'aspectRatio' => $aspectRatio,
                    ],
                ],
            ]);

        if (!$response->successful()) {
            // Carry the HTTP status as the exception code so failover can
            // classify account-unusable errors (429 credit/quota, 401/403 auth).
            throw new \RuntimeException(
                "Gemini image API failed: {$response->status()} - {$response->body()}",
                $response->status()
            );
        }

        $candidates = $response->json('candidates', []);
        if (empty($candidates)) {
            throw new \RuntimeException('No candidates in Gemini image response');
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['inlineData']['mimeType'], $part['inlineData']['data'])
                && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                return [
                    'bytes' => base64_decode($part['inlineData']['data']),
                    'mime_type' => $part['inlineData']['mimeType'],
                ];
            }
        }

        throw new \RuntimeException('No image found in Gemini response');
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    /**
     * @return array<string, string>
     */
    public static function getImageModelOptions(): array
    {
        /** @var array<string, array{label: string, cost_per_image: float|null}> $models */
        $models = config('image-generation.gemini.models', []);

        return collect($models)
            ->map(function (array $model): string {
                if ($model['cost_per_image'] !== null) {
                    $cost = number_format($model['cost_per_image'], 3, ',', ' ');

                    return "{$model['label']} (~{$cost} EUR)";
                }

                return $model['label'];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function getAspectRatioOptions(): array
    {
        return config('image-generation.gemini.aspect_ratios', []);
    }

    public static function supportsCostEstimate(): bool
    {
        return false;
    }

    public static function defaultImageModel(): string
    {
        return (string) config('image-generation.gemini.model', 'gemini-2.5-flash-image');
    }

    public static function isConfigured(): bool
    {
        return !empty(config('image-generation.gemini.api_key'));
    }
}
