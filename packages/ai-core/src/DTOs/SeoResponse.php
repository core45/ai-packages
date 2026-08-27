<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Response DTO for `AiClient::generateSEO()`.
 *
 * phasea's `POST /api/v1/ai/content/seo` returns a flat envelope
 * (`{"data": {"seo_title", "seo_description", "focus_keyphrase",
 * "secondary_keyphrases": [...], "improvement_notes", "meta": {...}}}` —
 * docs/ai-contract.md §3) with no `metadata` wrapper and no combined
 * `seo_keywords` string. `seoKeywords` is synthesized client-side from
 * `focusKeyphrase` + `secondaryKeyphrases`.
 *
 * `metadata` is a computed accessor array preserving the exact key set the
 * real callers (`SeoImprovementService::improveRemote()`,
 * `ContentAIService::generateSEORemote()`, `AIGenerateContentAction::makeSEO()`)
 * already read via `$response->metadata['seo_title']` etc. — no caller
 * needed to change how it reads the result, only where the values come from.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 */
final readonly class SeoResponse
{
    /**
     * @param  array<int, string>  $secondaryKeyphrases
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $seoTitle,
        public string $seoDescription,
        public string $focusKeyphrase,
        public array $secondaryKeyphrases,
        public string $improvementNotes,
        public string $seoKeywords,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $envelope = $data['data'] ?? null;
        $envelope = is_array($envelope) ? $envelope : [];

        $seoTitle = $envelope['seo_title'] ?? '';
        $seoDescription = $envelope['seo_description'] ?? '';
        $focusKeyphrase = $envelope['focus_keyphrase'] ?? '';
        $improvementNotes = $envelope['improvement_notes'] ?? '';
        $secondaryKeyphrases = $envelope['secondary_keyphrases'] ?? [];

        $seoTitle = is_string($seoTitle) ? $seoTitle : '';
        $seoDescription = is_string($seoDescription) ? $seoDescription : '';
        $focusKeyphrase = is_string($focusKeyphrase) ? $focusKeyphrase : '';
        $improvementNotes = is_string($improvementNotes) ? $improvementNotes : '';

        /** @var array<int, string> $secondaryKeyphrases */
        $secondaryKeyphrases = is_array($secondaryKeyphrases)
            ? array_values(array_filter($secondaryKeyphrases, 'is_string'))
            : [];

        $seoKeywords = implode(', ', array_filter(
            [$focusKeyphrase, ...$secondaryKeyphrases],
            static fn (string $phrase): bool => $phrase !== '',
        ));

        $metadata = [
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'seo_keywords' => $seoKeywords,
            'focus_keyphrase' => $focusKeyphrase,
            'improvement_notes' => $improvementNotes,
        ];

        // Phase 33: the optional `content` key (rewritten body HTML) is
        // present ONLY when the request context carried content-rewrite issue
        // ids — pass it through so `SeoImprovementService::improveRemote()`'s
        // apply branch lights up (audit F2).
        $content = $envelope['content'] ?? null;

        if (is_string($content) && $content !== '') {
            $metadata['content'] = $content;
        }

        return new self(
            seoTitle: $seoTitle,
            seoDescription: $seoDescription,
            focusKeyphrase: $focusKeyphrase,
            secondaryKeyphrases: $secondaryKeyphrases,
            improvementNotes: $improvementNotes,
            seoKeywords: $seoKeywords,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'focus_keyphrase' => $this->focusKeyphrase,
            'secondary_keyphrases' => $this->secondaryKeyphrases,
            'improvement_notes' => $this->improvementNotes,
            'seo_keywords' => $this->seoKeywords,
        ];
    }
}
