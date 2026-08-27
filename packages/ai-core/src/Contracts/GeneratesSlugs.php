<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

/**
 * Slug generation seam: translation flows derive URL slugs from generated
 * titles. The consumer binds its implementation (the host's SlugGenerator).
 */
interface GeneratesSlugs
{
    public function make(string $title): string;
}
