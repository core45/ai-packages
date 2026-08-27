<?php

declare(strict_types=1);

namespace Core45\AiCore\Support;

/**
 * The prompt-key catalog, owned by `ai-core` but populated by whoever owns each
 * key (plan §9 D14).
 *
 * `AIPromptOverride::availablePromptKeys()` used to enumerate every key in the
 * system inline — `product_rewrite_system`, `webinar_part_element_seo_system`
 * and the rest. That put `ai-core`, the package every other one depends on, in
 * the position of knowing the taxonomy of `ai-products` and `ai-webinars`,
 * which is exactly the leak §2.5 item 3 named. An application that installs
 * `ai-core` alone still got a dropdown offering webinar prompts it has no code
 * for.
 *
 * The inversion: `ai-core` registers only the keys it owns, each domain package
 * registers its own from its own service provider, and this registry is the
 * only thing that sees the union. Nothing here names a domain.
 *
 * REGISTRATION ORDER IS PRESERVED, and it is what the Filament dropdown shows,
 * so keys arrive grouped by the package that owns them rather than in the hand
 * -maintained order of the old array. Registering the same key twice overwrites
 * — last writer wins — which is what lets a consuming application relabel a
 * packaged key without patching the package.
 */
final class PromptKeyRegistry
{
    /**
     * @var array<string, array{label: string, color: string, template: string|null}>
     */
    private array $keys = [];

    /**
     * Register one prompt key.
     *
     * @param  string  $key  the `prompt_key` column value
     * @param  string  $label  human-readable, shown in the admin dropdown
     * @param  string  $color  Filament badge colour; see the note on
     *                         `AIPromptOverride::promptKeyBadgeColor()`
     * @param  string|null  $template  the phasea prompt template this key's
     *                                 overrides ride, used to preview the base
     *                                 prompt. Null means no live surface routes
     *                                 this key and the preview falls back to its
     *                                 explanatory placeholder.
     */
    public function register(string $key, string $label, string $color = 'gray', ?string $template = null): void
    {
        $this->keys[$key] = [
            'label' => $label,
            'color' => $color,
            'template' => $template,
        ];
    }

    /**
     * Register several keys at once.
     *
     * @param  array<string, array{label: string, color?: string, template?: string|null}>  $entries
     */
    public function registerMany(array $entries): void
    {
        foreach ($entries as $key => $entry) {
            $this->register(
                $key,
                $entry['label'],
                $entry['color'] ?? 'gray',
                $entry['template'] ?? null,
            );
        }
    }

    /**
     * Every registered key, as `key => label` — the shape Filament's
     * `->options()` wants.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return array_map(static fn (array $entry): string => $entry['label'], $this->keys);
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function labelFor(string $key): ?string
    {
        return $this->keys[$key]['label'] ?? null;
    }

    /**
     * Falls back to `gray` for an unregistered key rather than throwing: a row
     * can outlive the package that registered its key (uninstall the package,
     * the override rows stay), and a table cell is no place to fail.
     */
    public function colorFor(string $key): string
    {
        return $this->keys[$key]['color'] ?? 'gray';
    }

    public function templateFor(string $key): ?string
    {
        return $this->keys[$key]['template'] ?? null;
    }
}
