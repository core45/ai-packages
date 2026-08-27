<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\ImageGeneration;

use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Support\Facades\Http;

class FalAiService implements GeneratesImages
{
    use LogsAIOperations;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('fal-ai.api_key', '');
        $this->baseUrl = (string) config('fal-ai.base_url', 'https://queue.fal.run');
        $this->timeout = (int) config('fal-ai.timeout', 120);
    }

    public function generate(string $prompt, array $params = []): array
    {
        $modelId = (string) ($params['model'] ?? config('fal-ai.default_image_model'));
        unset($params['model']);

        $result = $this->generateImage($modelId, $prompt, $params);
        $bytes = $this->downloadImage($result['url']);

        return [
            'bytes' => $bytes,
            'mime_type' => $result['content_type'],
        ];
    }

    public function providerName(): string
    {
        return 'fal-ai';
    }

    public static function supportsCostEstimate(): bool
    {
        return true;
    }

    public static function defaultImageModel(): string
    {
        return (string) config('fal-ai.default_image_model');
    }

    public static function isConfigured(): bool
    {
        return !empty(config('fal-ai.api_key'));
    }

    /**
     * Generate an image using a fal.ai model.
     *
     * @param  array<string, mixed>  $params  Additional parameters (aspect_ratio, etc.)
     * @return array{url: string, width: int, height: int, content_type: string}
     *
     * @throws \RuntimeException
     */
    public function generateImage(string $modelId, string $prompt, array $params = []): array
    {
        $this->logDebug('FalAiService - generateImage', [
            'model' => $modelId,
            'prompt_length' => strlen($prompt),
        ]);

        $payload = array_merge(['prompt' => $prompt], $params);

        $submitResponse = Http::timeout($this->timeout)
            ->withHeaders($this->getPostHeaders())
            ->post("{$this->baseUrl}/{$modelId}", $payload);

        if (!$submitResponse->successful()) {
            // Carry the HTTP status as the exception code so failover can
            // classify account-unusable errors (429 credit/quota, 401/403 auth).
            throw new \RuntimeException(
                "fal.ai submit failed: {$submitResponse->status()} - {$submitResponse->body()}",
                $submitResponse->status()
            );
        }

        $requestId = $submitResponse->json('request_id');
        if (!$requestId) {
            return $this->extractImageFromResponse($submitResponse->json());
        }

        $statusUrl = $submitResponse->json('status_url');
        $responseUrl = $submitResponse->json('response_url');

        return $this->pollForResult($statusUrl, $responseUrl, $requestId);
    }

    /**
     * Download an image from a URL and return its binary content.
     *
     * @throws \RuntimeException
     */
    public function downloadImage(string $url): string
    {
        $response = Http::timeout($this->timeout)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException("Failed to download image from {$url}: {$response->status()}");
        }

        return $response->body();
    }

    /**
     * Get available image model options for form dropdown.
     *
     * @return array<string, string>
     */
    public static function getImageModelOptions(): array
    {
        /** @var array<string, array{label: string, type: string, cost_per_image: float, supports_aspect_ratio: bool}> $models */
        $models = config('fal-ai.models', []);

        return collect($models)
            ->filter(fn (array $model): bool => $model['type'] === 'image')
            ->map(function (array $model): string {
                $cost = number_format($model['cost_per_image'], 3, ',', ' ');

                return "{$model['label']} (~{$cost} EUR)";
            })
            ->all();
    }

    /**
     * Get available aspect ratio options for form dropdown.
     *
     * @return array<string, string>
     */
    public static function getAspectRatioOptions(): array
    {
        return config('fal-ai.aspect_ratios', []);
    }

    /**
     * Estimate total cost for image generation.
     */
    public static function estimateCost(string $modelId, int $galleryCount, int $productCount, bool $includesDescription): float
    {
        $models = config('fal-ai.models', []);
        $costPerImage = (float) ($models[$modelId]['cost_per_image'] ?? 0.04);
        $imagesPerProduct = $includesDescription ? $galleryCount + 2 : $galleryCount;

        return $costPerImage * $imagesPerProduct * $productCount;
    }

    /**
     * Calculate total estimated image count.
     */
    public static function estimateImageCount(int $galleryCount, int $productCount, bool $includesDescription): int
    {
        $imagesPerProduct = $includesDescription ? $galleryCount + 2 : $galleryCount;

        return $imagesPerProduct * $productCount;
    }

    /**
     * Poll the fal.ai queue for a completed result.
     *
     * @return array{url: string, width: int, height: int, content_type: string}
     *
     * @throws \RuntimeException
     */
    private function pollForResult(?string $statusUrl, ?string $responseUrl, string $requestId): array
    {
        if ($statusUrl === null || $responseUrl === null) {
            throw new \RuntimeException("fal.ai submit did not return status_url/response_url for request {$requestId}");
        }

        $maxAttempts = 60;
        $attempt = 0;
        $consecutiveFailures = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $waitSeconds = min($attempt * 2, 10);
            sleep($waitSeconds);

            $statusResponse = Http::timeout($this->timeout)
                ->withHeaders($this->getAuthHeaders())
                ->get($statusUrl);

            if (!$statusResponse->successful()) {
                $consecutiveFailures++;
                $this->logDebug('FalAiService - Status check failed', [
                    'url' => $statusUrl,
                    'status' => $statusResponse->status(),
                    'body' => $statusResponse->body(),
                    'attempt' => $attempt,
                    'consecutive_failures' => $consecutiveFailures,
                ]);

                if ($consecutiveFailures >= 5) {
                    throw new \RuntimeException(
                        "fal.ai status check failed after {$consecutiveFailures} consecutive attempts: {$statusResponse->status()}",
                        $statusResponse->status()
                    );
                }

                continue;
            }

            $consecutiveFailures = 0;

            $status = $statusResponse->json('status');

            if ($status === 'COMPLETED') {
                $resultResponse = Http::timeout($this->timeout)
                    ->withHeaders($this->getAuthHeaders())
                    ->get($responseUrl);

                if (!$resultResponse->successful()) {
                    throw new \RuntimeException(
                        "fal.ai result fetch failed: {$resultResponse->status()}",
                        $resultResponse->status()
                    );
                }

                return $this->extractImageFromResponse($resultResponse->json());
            }

            if ($status === 'FAILED') {
                $error = $statusResponse->json('error', 'Unknown error');
                throw new \RuntimeException("fal.ai generation failed: {$error}");
            }
        }

        throw new \RuntimeException('fal.ai generation timed out after polling');
    }

    /**
     * Extract image data from a fal.ai response.
     *
     * @param  array<string, mixed>  $responseData
     * @return array{url: string, width: int, height: int, content_type: string}
     *
     * @throws \RuntimeException
     */
    private function extractImageFromResponse(array $responseData): array
    {
        $images = $responseData['images'] ?? $responseData['output'] ?? [];

        if (!empty($images) && is_array($images)) {
            return $this->normalizeImageData($images[0]);
        }

        if (isset($responseData['url'])) {
            return $this->normalizeImageData($responseData);
        }

        throw new \RuntimeException('No image found in fal.ai response');
    }

    /**
     * Normalize image data from various fal.ai response formats.
     *
     * @param  array<string, mixed>  $data
     * @return array{url: string, width: int, height: int, content_type: string}
     */
    private function normalizeImageData(array $data): array
    {
        return [
            'url' => (string) ($data['url'] ?? ''),
            'width' => (int) ($data['width'] ?? 0),
            'height' => (int) ($data['height'] ?? 0),
            'content_type' => (string) ($data['content_type'] ?? 'image/png'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getAuthHeaders(): array
    {
        return [
            'Authorization' => "Key {$this->apiKey}",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getPostHeaders(): array
    {
        return [
            'Authorization' => "Key {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];
    }
}
