<?php

namespace Core45\AiCore\Contracts;

interface AIQueryable
{
    /**
     * Get fields that can be queried/searched by AI.
     * These fields will be exposed to the AI for filtering.
     * Sensitive fields should NOT be included (use $hidden on model).
     */
    public function getAIQueryableFields(): array;

    /**
     * Get available filter scopes for AI queries.
     * Format: ['scope_name' => 'Human readable description', ...]
     * Example: ['lowStock' => 'Products with stock below alert threshold']
     */
    public function getAIFilterScopes(): array;

    /**
     * Get available actions that AI can perform on this model.
     * Format: ['action_name' => ['description' => '...', 'fields' => [...]], ...]
     * Note: 'delete' action should NOT be included.
     */
    public function getAIActions(): array;

    /**
     * Get the Filament resource class for linking to admin pages.
     * Return null if no Filament resource exists.
     */
    public function getFilamentResource(): ?string;

    /**
     * Get a human-readable label for this model (singular).
     */
    public static function getAIModelLabel(): string;

    /**
     * Get a human-readable label for this model (plural).
     */
    public static function getAIModelPluralLabel(): string;
}
