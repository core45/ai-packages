<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Content;

use Core45\AiCore\Contracts\ProvidesMandatoryWords;

/**
 * The default when the host has not bound its own mandatory-word store.
 *
 * `ProvidesMandatoryWords::asProfileParameter()` is documented on the
 * interface itself as returning "either `[]` or
 * `['profile' => ['mandatory_words' => list<string>]]`" — an empty array is
 * not a stand-in for missing behaviour, it is the interface's own answer for
 * "the store mandates nothing." This class returns exactly that.
 *
 * No call site for this contract exists inside ai-core: it is resolved
 * unconditionally (no `bound()` guard, no try/catch) from
 * `ai-posts/src/Services/BlogPipeline/Modules/DynamicContentModule.php` and
 * two `ai-posts/src/Services/BlogTopics/*WriterService.php` classes, every
 * time they build a prompt payload. Because `AiCoreServiceProvider` is
 * registered once for the whole application container, binding the default
 * here fixes the fresh-install `BindingResolutionException` for those
 * call sites too, without touching ai-posts.
 *
 * `bindIf`: a host with its own mandatory-word list keeps it.
 */
final class NullMandatoryWordsProvider implements ProvidesMandatoryWords
{
    /**
     * @return array<string, mixed>
     */
    public function asProfileParameter(): array
    {
        return [];
    }
}
