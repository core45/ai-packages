<?php

namespace Core45\AiCore\Contracts;

interface AIContentable
{
    /**
     * Get context data for AI content generation prompts.
     * Include relevant information like name, category, related data.
     */
    public function getAIContext(string $locale): array;

    /**
     * Get the list of AI capabilities supported by this model.
     * Options: 'description', 'summary', 'seo', 'photo_analysis', 'specifications'
     */
    public function getAICapabilities(): array;

    /**
     * Get content fields that can be AI-generated.
     * Format: ['field_name' => 'rich|plain', ...]
     * 'rich' = HTML content, 'plain' = plain text
     */
    public function getAIContentFields(): array;

    /**
     * Get the media collection name for photo analysis.
     * Return null if photo analysis is not supported.
     */
    public function getAIAnalyzableMediaCollection(): ?string;
}
