<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

/**
 * Store-wide mandatory-word list, as a prompt parameter, for surfaces that have
 * no archetype in scope (blog posts, FAQs, pipeline modules).
 */
interface ProvidesMandatoryWords
{
    /**
     * The `parameters.profile` fragment phasea's `AntiSlopGuide` reads, or an
     * empty array when the store mandates nothing.
     *
     * @return array<string, mixed> either `[]` or `['profile' => ['mandatory_words' => list<string>]]`
     */
    public function asProfileParameter(): array;
}
