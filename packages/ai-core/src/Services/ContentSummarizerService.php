<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Enums\AiSurface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-powered service for generating SEO-optimized content summaries.
 *
 * Generates meta descriptions, excerpts, and content summary variations, falling
 * back to simple text processing when AI is disabled or encounters errors.
 *
 * v2.6 design §2.5 (phase 48): this service HOLDS NO INSTRUCTION TEXT. Every
 * entry point posts `prompt/run` naming a phasea-owned template and sends
 * structured parameters; the four `build*Prompt()` methods and their four
 * persona strings are deleted. What stays is host work: `stripAndTrim()` and
 * `capForContext()` are input hygiene, `parseVariations()` and the `Str::limit`
 * ceilings are response post-processing, and every fallback path is unchanged.
 *
 * TWO OBSERVABLE CHANGES CAME WITH THE MOVE, both recorded in the phasea
 * template docblocks rather than claimed as byte-identity:
 *
 * 1. The personas are now DELIVERED. `summarize()`, `generateMetaDescription()`
 *    and `suggestExcerpt()` built a `$systemPrompt` local and then called
 *    `AiClient::summarize()`, which posts `{text, operation_id}` only — so the
 *    authored instruction was dropped and phasea's `SummarizeAgent` supplied a
 *    generic one instead. Phase 33 already recorded this defect for
 *    `generateVariations()`; this closes it for the other three.
 * 2. The billing label moves `summarize` → `seo` for those same three. It was
 *    stamped by `api/ai/summarize`, which discards the caller's surface; the
 *    two `ai_task_routes` rows are identical, so nothing re-routes.
 */
class ContentSummarizerService
{
    /*
     * v2.6: the $provider/$model properties and their constructor parameters
     * were removed. They were seeded from config('ai.assistant.default_*') and
     * used only to stamp log fields — the host does not choose the model, phasea
     * routes the summarize task, so those fields asserted something the host
     * could not know.
     */

    /**
     * Check whether the AI assistant is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enabled', false);
    }

    /**
     * Generate a concise, SEO-optimized summary of the given content.
     */
    public function summarize(string $content, string $locale, ?string $keyphrase = null, int $maxLength = 160): string
    {
        $plainContent = $this->stripAndTrim($content);

        if ($plainContent === '') {
            return '';
        }

        if (!$this->isEnabled()) {
            return Str::limit($plainContent, $maxLength, '');
        }

        try {
            $client = app(AiClientFactory::class)->for(AiSurface::Seo);

            $result = $client->runPrompt(
                'seo.summary',
                [
                    'content' => $this->capForContext($plainContent),
                    'locale' => $locale,
                    'keyphrase' => $keyphrase,
                    'max_length' => $maxLength,
                ],
                (string) Str::uuid(),
                ['timeout' => (int) config('ai.assistant.timeout', 300)],
            );

            $summary = trim($result->text);

            if ($summary === '') {
                return Str::limit($plainContent, $maxLength, '');
            }

            return Str::limit($summary, $maxLength, '');
        } catch (\Throwable $e) {
            Log::error('[ContentSummarizerService] summarize failed', [
                'error' => $e->getMessage(),
                'locale' => $locale,
            ]);

            return Str::limit($plainContent, $maxLength, '');
        }
    }

    /**
     * Generate a meta description optimized for search engine result pages.
     *
     * Produces a description between 120-160 characters, incorporating the
     * page title and optional focus keyphrase for maximum relevance.
     */
    public function generateMetaDescription(string $content, string $title, string $locale, ?string $keyphrase = null): string
    {
        $plainContent = $this->stripAndTrim($content);

        if ($plainContent === '') {
            return '';
        }

        if (!$this->isEnabled()) {
            return Str::limit($plainContent, 160, '');
        }

        try {
            $client = app(AiClientFactory::class)->for(AiSurface::Seo);

            $result = $client->runPrompt(
                'seo.meta_description',
                [
                    'content' => $this->capForContext($plainContent),
                    'title' => $title,
                    'locale' => $locale,
                    'keyphrase' => $keyphrase,
                ],
                (string) Str::uuid(),
                ['timeout' => (int) config('ai.assistant.timeout', 300)],
            );

            $description = trim($result->text);

            if ($description === '') {
                return Str::limit($plainContent, 160, '');
            }

            return Str::limit($description, 160, '');
        } catch (\Throwable $e) {
            Log::error('[ContentSummarizerService] generateMetaDescription failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'locale' => $locale,
            ]);

            return Str::limit($plainContent, 160, '');
        }
    }

    /**
     * Generate a longer excerpt suitable for content previews and cards.
     */
    public function suggestExcerpt(string $content, string $locale, int $maxLength = 300): string
    {
        $plainContent = $this->stripAndTrim($content);

        if ($plainContent === '') {
            return '';
        }

        if (!$this->isEnabled()) {
            return Str::limit($plainContent, $maxLength, '');
        }

        try {
            $client = app(AiClientFactory::class)->for(AiSurface::Seo);

            $result = $client->runPrompt(
                'seo.excerpt',
                [
                    'content' => $this->capForContext($plainContent),
                    'locale' => $locale,
                    'max_length' => $maxLength,
                ],
                (string) Str::uuid(),
                ['timeout' => (int) config('ai.assistant.timeout', 300)],
            );

            $excerpt = trim($result->text);

            if ($excerpt === '') {
                return Str::limit($plainContent, $maxLength, '');
            }

            return Str::limit($excerpt, $maxLength, '');
        } catch (\Throwable $e) {
            Log::error('[ContentSummarizerService] suggestExcerpt failed', [
                'error' => $e->getMessage(),
                'locale' => $locale,
            ]);

            return Str::limit($plainContent, $maxLength, '');
        }
    }

    /**
     * Generate multiple meta description variations for A/B testing or selection.
     *
     * Phase 33 rerouted this off `api/ai/summarize` (a true-summary surface that
     * dropped the variations SYSTEM prompt on the floor) onto `completeText` with
     * surface `seo`. THAT REROUTE NEVER WORKED: phasea's
     * `TextCompletionController` accepts exactly eight wire surfaces and `seo` is
     * not among them, so every call 422'd, was swallowed by the `catch` below and
     * degraded to a single `Str::limit($plainContent, 160)` excerpt — the
     * "variations" feature has been returning one truncated copy of the page
     * body. v2.6 phase 48 fixes it by moving to `prompt/run`, which routes on the
     * TEMPLATE's task (D-48-1) rather than on a wire surface whitelist.
     *
     * The numbered-variations parsing is unchanged (cs12 parity).
     *
     * @return array<int, string>
     */
    public function generateVariations(string $content, string $title, string $locale, ?string $keyphrase = null, int $count = 3): array
    {
        $plainContent = $this->stripAndTrim($content);

        if ($plainContent === '') {
            return [''];
        }

        if (!$this->isEnabled()) {
            return [Str::limit($plainContent, 160, '')];
        }

        try {
            $client = app(AiClientFactory::class)->for(AiSurface::Seo);

            $result = $client->runPrompt(
                'seo.meta_description_variations',
                [
                    'content' => $this->capForContext($plainContent),
                    'title' => $title,
                    'locale' => $locale,
                    'keyphrase' => $keyphrase,
                    'count' => $count,
                ],
                (string) Str::uuid(),
                ['timeout' => (int) config('ai.assistant.timeout', 300)],
            );

            // The SAME `$count` that was sent as a parameter. A divergence here
            // silently truncates or pads the result, so phasea renders "generate
            // exactly N" from one number and this parser expects that same N.
            $variations = $this->parseVariations($result->text, $count);

            if ($variations === []) {
                return [Str::limit($plainContent, 160, '')];
            }

            return $variations;
        } catch (\Throwable $e) {
            Log::error('[ContentSummarizerService] generateVariations failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'locale' => $locale,
            ]);

            return [Str::limit($plainContent, 160, '')];
        }
    }

    /**
     * Strip HTML tags and trim whitespace from content.
     */
    protected function stripAndTrim(string $content): string
    {
        return trim(strip_tags($content));
    }

    /**
     * Cap tenant content to the SEO context window before it is sent.
     *
     * v2.6: this is INPUT HYGIENE, not prompt authorship — the same class as
     * `stripAndTrim()` above — so it stays here after the prompt builders moved
     * to phasea. It was previously applied inside each of the four deleted
     * `build*Prompt()` methods, identically, which is why one helper replaces
     * four copies.
     *
     * The width is coupled to `SeoSummaryPrompt::CONTENT_LIMIT` in phasea, which
     * validates `content` at the same 5000. Raising `AI_SEO_CONTEXT_LIMIT` above
     * that ceiling without raising phasea's would turn today's silent truncation
     * into a 422 — the two move together.
     */
    protected function capForContext(string $content): string
    {
        return Str::limit($content, (int) config('ai.assistant.content.seo_context_limit', 5000), '');
    }

    /**
     * Parse numbered variations from AI response text.
     *
     * @return array<int, string>
     */
    protected function parseVariations(string $responseText, int $expectedCount): array
    {
        $lines = explode("\n", trim($responseText));
        $variations = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Remove numbered prefix (e.g., "1. ", "2) ", "1: ")
            $cleaned = preg_replace('/^\d+[\.\)\:]\s*/', '', $line);

            if ($cleaned !== null && $cleaned !== '') {
                $variations[] = Str::limit(trim($cleaned), 160, '');
            }

            if (count($variations) >= $expectedCount) {
                break;
            }
        }

        return $variations;
    }
}
