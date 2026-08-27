<?php

namespace Core45\AiCore\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownToHtmlConverter
{
    protected MarkdownConverter $converter;

    public function __construct()
    {
        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Convert content to HTML if it contains markdown patterns.
     * Returns original content if it's already HTML or plain text.
     */
    public function convertIfNeeded(string $content): string
    {
        $content = trim($content);

        if (empty($content)) {
            return $content;
        }

        // If content is primarily HTML (starts with HTML tag or contains significant HTML), return as-is
        if ($this->isPrimarilyHtml($content)) {
            return $content;
        }

        // If content contains markdown patterns, convert it
        if ($this->containsMarkdown($content)) {
            return $this->convert($content);
        }

        // Plain text without markdown - wrap in paragraph tags for RichEditor
        return $this->wrapPlainText($content);
    }

    /**
     * Force convert markdown to HTML regardless of detection.
     */
    public function convert(string $markdown): string
    {
        $html = $this->converter->convert($markdown)->getContent();

        // Clean up: remove trailing newlines and normalize whitespace
        $html = trim($html);

        return $html;
    }

    /**
     * Check if content is primarily HTML (not markdown).
     */
    public function isPrimarilyHtml(string $content): bool
    {
        // Check if content starts with an HTML tag
        if (preg_match('/^\s*<(p|div|ul|ol|h[1-6]|table|blockquote|section|article|span|strong|em)/i', $content)) {
            return true;
        }

        // Count HTML tags vs markdown patterns
        $htmlTagCount = preg_match_all('/<[a-z][^>]*>/i', $content);
        $closingTagCount = preg_match_all('/<\/[a-z]+>/i', $content);

        // If we have balanced HTML tags, it's likely HTML content
        if ($htmlTagCount > 2 && $closingTagCount > 2) {
            return true;
        }

        return false;
    }

    /**
     * Check if content contains markdown patterns that should be converted.
     */
    public function containsMarkdown(string $content): bool
    {
        $markdownPatterns = [
            '/(?<!\*)\*\*(?!\*)[\w\s]+\*\*(?!\*)/',  // **bold** (not ***)
            '/(?<!_)__(?!_)[\w\s]+__(?!_)/',          // __bold__
            '/(?<!\*)\*(?!\*)[\w\s]+\*(?!\*)/',       // *italic* (single asterisk)
            '/(?<!_)_(?!_)[\w\s]+_(?!_)/',            // _italic_ (single underscore)
            '/^#{1,6}\s+.+$/m',                        // # Headings
            '/^\s*[-*+]\s+.+$/m',                      // - list items
            '/^\s*\d+\.\s+.+$/m',                      // 1. numbered lists
            '/```[\s\S]*?```/',                        // ```code blocks```
            '/`[^`]+`/',                               // `inline code`
            '/^\s*>\s+.+$/m',                          // > blockquotes
            '/\[([^\]]+)\]\(([^)]+)\)/',              // [links](url)
        ];

        foreach ($markdownPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrap plain text in paragraph tags, preserving line breaks.
     */
    protected function wrapPlainText(string $content): string
    {
        // Split by double newlines (paragraph breaks)
        $paragraphs = preg_split('/\n\s*\n/', $content);

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                // Convert single newlines to <br> within paragraphs
                $paragraph = nl2br(e($paragraph), false);
                $html .= "<p>{$paragraph}</p>";
            }
        }

        return $html ?: "<p>{$content}</p>";
    }

    /**
     * Static helper for quick conversion.
     */
    public static function toHtml(string $content): string
    {
        return (new self)->convertIfNeeded($content);
    }

    /**
     * Static helper to check if conversion is needed.
     */
    public static function needsConversion(string $content): bool
    {
        $instance = new self;

        return !$instance->isPrimarilyHtml($content) && $instance->containsMarkdown($content);
    }
}
