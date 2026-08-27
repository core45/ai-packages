<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

/**
 * Splits an HTML fragment into chunks at top-level element boundaries.
 *
 * Used to translate large post bodies in several smaller LLM calls instead of
 * one monolithic request that would exceed the HTTP timeout. Splitting only ever
 * happens where the nesting depth returns to zero, so tags are never bisected and
 * concatenating the returned chunks reproduces the original input byte-for-byte.
 */
class HtmlChunker
{
    /**
     * HTML void elements that never change nesting depth.
     *
     * @var array<int, string>
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Split HTML into chunks, each at most $maxChars where the structure allows.
     *
     * A single top-level block larger than $maxChars is kept whole rather than
     * bisected, so an individual chunk may exceed $maxChars.
     *
     * @return array<int, string>
     */
    public function split(string $html, int $maxChars): array
    {
        if ($html === '') {
            return [];
        }

        if ($maxChars <= 0 || mb_strlen($html) <= $maxChars) {
            return [$html];
        }

        return $this->groupSegments($this->topLevelSegments($html), $maxChars);
    }

    /**
     * Break the HTML into top-level segments, each spanning one root-level element
     * (and any text/void markup that precedes the next root-level element).
     *
     * @return array<int, string>
     */
    private function topLevelSegments(string $html): array
    {
        $segments = [];
        $length = strlen($html);
        $depth = 0;
        $segmentStart = 0;
        $i = 0;

        while ($i < $length) {
            if ($html[$i] !== '<') {
                $i++;

                continue;
            }

            if (substr($html, $i, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $i);
                $i = $commentEnd === false ? $length : $commentEnd + 3;

                continue;
            }

            $tagEnd = strpos($html, '>', $i);
            if ($tagEnd === false) {
                break; // Malformed tail — emit the remainder as one segment below.
            }

            $tag = substr($html, $i, $tagEnd - $i + 1);
            $i = $tagEnd + 1;

            if ($this->isClosingTag($tag)) {
                $depth = max(0, $depth - 1);
                if ($depth === 0) {
                    $segments[] = substr($html, $segmentStart, $i - $segmentStart);
                    $segmentStart = $i;
                }

                continue;
            }

            if (!$this->isVoidTag($tag)) {
                $depth++;
            }
        }

        if ($segmentStart < $length) {
            $segments[] = substr($html, $segmentStart);
        }

        return $segments;
    }

    /**
     * Greedily concatenate segments into chunks that stay under $maxChars.
     *
     * @param  array<int, string>  $segments
     * @return array<int, string>
     */
    private function groupSegments(array $segments, int $maxChars): array
    {
        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            if ($current !== '' && mb_strlen($current.$segment) > $maxChars) {
                $chunks[] = $current;
                $current = $segment;

                continue;
            }

            $current .= $segment;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function isClosingTag(string $tag): bool
    {
        return str_starts_with($tag, '</');
    }

    private function isVoidTag(string $tag): bool
    {
        if (str_ends_with($tag, '/>')) {
            return true;
        }

        if (!preg_match('/^<\s*([a-zA-Z0-9]+)/', $tag, $matches)) {
            return false;
        }

        return in_array(strtolower($matches[1]), self::VOID_ELEMENTS, true);
    }
}
