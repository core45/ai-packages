<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests\Unit;

use Core45\AiCore\Services\HtmlChunker;
use PHPUnit\Framework\TestCase;

class HtmlChunkerTest extends TestCase
{
    private HtmlChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new HtmlChunker;
    }

    public function test_returns_empty_array_for_empty_string(): void
    {
        $this->assertSame([], $this->chunker->split('', 50));
    }

    public function test_returns_single_chunk_when_under_threshold(): void
    {
        $html = '<p>Short content.</p>';

        $this->assertSame([$html], $this->chunker->split($html, 1000));
    }

    public function test_splits_into_multiple_chunks_at_top_level_boundaries(): void
    {
        $block = '<p>'.str_repeat('a', 30).'</p>'; // 37 chars
        $html = str_repeat($block, 4);

        $chunks = $this->chunker->split($html, 50);

        $this->assertCount(4, $chunks);
        $this->assertSame($html, implode('', $chunks), 'Concatenation must equal the original input');
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, mb_strlen($chunk));
        }
    }

    public function test_concatenation_equals_original_exactly(): void
    {
        $html = '<h2>Heading</h2><p>First paragraph with some words.</p>'
            .'<ul><li>One</li><li>Two</li></ul><p>Closing paragraph here.</p>';

        $chunks = $this->chunker->split($html, 40);

        $this->assertSame($html, implode('', $chunks));
    }

    public function test_preserves_utf8_characters(): void
    {
        $html = '<p>Zażółć gęślą jaźń</p><p>Półtora łokcia</p>';

        $chunks = $this->chunker->split($html, 25);

        $this->assertSame($html, implode('', $chunks));
        $this->assertStringContainsString('Zażółć gęślą jaźń', $chunks[0]);
    }

    public function test_keeps_nested_elements_intact_in_one_chunk(): void
    {
        $html = '<div><p>a</p><p>b</p></div><p>c</p>';

        $chunks = $this->chunker->split($html, 10);

        $this->assertSame('<div><p>a</p><p>b</p></div>', $chunks[0], 'A nested block must never be split across chunks');
        $this->assertSame($html, implode('', $chunks));
    }

    public function test_oversized_single_block_stays_whole(): void
    {
        $bigBlock = '<p>'.str_repeat('a', 100).'</p>'; // 107 chars, exceeds threshold
        $html = $bigBlock.'<p>b</p>';

        $chunks = $this->chunker->split($html, 50);

        $this->assertSame($bigBlock, $chunks[0], 'An oversized top-level block stays whole rather than being bisected');
        $this->assertSame($html, implode('', $chunks));
    }

    public function test_leading_text_preamble_stays_with_first_block(): void
    {
        // Mirrors the citation-instructions preamble prepended before HTML.
        $html = "IMPORTANT: preserve markers.\n\n<p>aaaaa</p><p>bbbbb</p>";

        $chunks = $this->chunker->split($html, 30);

        $this->assertStringStartsWith('IMPORTANT: preserve markers.', $chunks[0]);
        $this->assertSame($html, implode('', $chunks));
    }

    public function test_void_elements_do_not_break_nesting_depth(): void
    {
        $html = '<p>before<br>after</p><hr><p>next</p>';

        $chunks = $this->chunker->split($html, 15);

        $this->assertSame($html, implode('', $chunks));
        // The first paragraph (containing the void <br>) must remain a single coherent unit.
        $this->assertStringContainsString('<p>before<br>after</p>', $chunks[0]);
    }
}
