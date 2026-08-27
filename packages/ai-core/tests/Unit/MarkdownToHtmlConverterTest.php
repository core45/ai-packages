<?php

namespace Core45\AiCore\Tests\Unit;

use Core45\AiCore\Services\MarkdownToHtmlConverter;
use PHPUnit\Framework\TestCase;

class MarkdownToHtmlConverterTest extends TestCase
{
    protected MarkdownToHtmlConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new MarkdownToHtmlConverter;
    }

    public function test_converts_bold_markdown_to_html(): void
    {
        $markdown = 'This is **bold** text';
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringNotContainsString('**', $result);
    }

    public function test_converts_italic_markdown_to_html(): void
    {
        $markdown = 'This is *italic* text';
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function test_converts_headings_to_html(): void
    {
        $markdown = "# Heading 1\n## Heading 2\n### Heading 3";
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<h1>', $result);
        $this->assertStringContainsString('<h2>', $result);
        $this->assertStringContainsString('<h3>', $result);
        $this->assertStringNotContainsString('##', $result);
    }

    public function test_converts_unordered_lists_to_html(): void
    {
        $markdown = "- Item 1\n- Item 2\n- Item 3";
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringNotContainsString('- Item', $result);
    }

    public function test_converts_ordered_lists_to_html(): void
    {
        $markdown = "1. First\n2. Second\n3. Third";
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<li>', $result);
    }

    public function test_converts_blockquotes_to_html(): void
    {
        $markdown = '> This is a quote';
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function test_converts_code_blocks_to_html(): void
    {
        $markdown = "```\ncode block\n```";
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<pre>', $result);
        $this->assertStringContainsString('<code>', $result);
        $this->assertStringNotContainsString('```', $result);
    }

    public function test_converts_inline_code_to_html(): void
    {
        $markdown = 'Use `code` inline';
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<code>', $result);
        $this->assertStringNotContainsString('`', $result);
    }

    public function test_converts_links_to_html(): void
    {
        $markdown = 'Click [here](https://example.com)';
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<a href="https://example.com">', $result);
        $this->assertStringContainsString('>here</a>', $result);
    }

    public function test_preserves_existing_html_content(): void
    {
        $html = '<p>This is <strong>already HTML</strong> content</p>';
        $result = $this->converter->convertIfNeeded($html);

        $this->assertEquals($html, $result);
    }

    public function test_preserves_complex_html_content(): void
    {
        $html = '<div><ul><li>Item 1</li><li>Item 2</li></ul></div>';
        $result = $this->converter->convertIfNeeded($html);

        $this->assertEquals($html, $result);
    }

    public function test_wraps_plain_text_in_paragraphs(): void
    {
        $text = 'This is plain text without any formatting';
        $result = $this->converter->convertIfNeeded($text);

        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('</p>', $result);
    }

    public function test_handles_empty_string(): void
    {
        $result = $this->converter->convertIfNeeded('');

        $this->assertEquals('', $result);
    }

    public function test_handles_whitespace_only(): void
    {
        $result = $this->converter->convertIfNeeded('   ');

        $this->assertEquals('', $result);
    }

    public function test_detects_markdown_patterns_correctly(): void
    {
        $this->assertTrue($this->converter->containsMarkdown('**bold**'));
        $this->assertTrue($this->converter->containsMarkdown('# Heading'));
        $this->assertTrue($this->converter->containsMarkdown('- list item'));
        $this->assertTrue($this->converter->containsMarkdown('`code`'));
        $this->assertTrue($this->converter->containsMarkdown('[link](url)'));

        $this->assertFalse($this->converter->containsMarkdown('Plain text'));
        $this->assertFalse($this->converter->containsMarkdown('<p>HTML</p>'));
    }

    public function test_detects_html_content_correctly(): void
    {
        $this->assertTrue($this->converter->isPrimarilyHtml('<p>Paragraph</p>'));
        $this->assertTrue($this->converter->isPrimarilyHtml('<div>Content</div>'));
        $this->assertTrue($this->converter->isPrimarilyHtml('<ul><li>Item</li></ul>'));

        $this->assertFalse($this->converter->isPrimarilyHtml('**bold**'));
        $this->assertFalse($this->converter->isPrimarilyHtml('Plain text'));
    }

    public function test_static_to_html_helper_works(): void
    {
        $markdown = '**bold** and *italic*';
        $result = MarkdownToHtmlConverter::toHtml($markdown);

        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function test_static_needs_conversion_helper_works(): void
    {
        $this->assertTrue(MarkdownToHtmlConverter::needsConversion('**bold**'));
        $this->assertFalse(MarkdownToHtmlConverter::needsConversion('<p>HTML</p>'));
        $this->assertFalse(MarkdownToHtmlConverter::needsConversion('Plain text'));
    }

    public function test_handles_mixed_markdown_content(): void
    {
        $markdown = <<<'MD'
# Product Features

This product has **amazing** features:

- Feature one
- Feature two
- Feature three

> Great for everyday use!

Visit [our website](https://example.com) for more.
MD;

        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<h1>', $result);
        $this->assertStringContainsString('<strong>amazing</strong>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<a href=', $result);

        $this->assertStringNotContainsString('#', $result);
        $this->assertStringNotContainsString('**', $result);
        $this->assertStringNotContainsString('- Feature', $result);
    }

    public function test_handles_polish_content_with_markdown(): void
    {
        $markdown = "## Opis produktu\n\nTo jest **bardzo dobry** produkt.\n\n- Cecha pierwsza\n- Cecha druga";
        $result = $this->converter->convertIfNeeded($markdown);

        $this->assertStringContainsString('<h2>', $result);
        $this->assertStringContainsString('<strong>bardzo dobry</strong>', $result);
        $this->assertStringContainsString('<ul>', $result);
    }
}
