<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride\Schemas;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AIPromptOverrideForm
{
    /**
     * The phasea template whose `system_prompt` is the base prompt an admin
     * writes an override against now travels with the key, supplied by whichever
     * package registered it (plan §9 D14).
     *
     * It used to be a const map here, which meant this package carried a second
     * full copy of the prompt taxonomy — `product_*`, `webinar_part_element_*`
     * and the rest — alongside the one in `ai-core`. Two enumerations of the same
     * thing in two packages, either of which could go stale against the domain
     * services that actually own the keys.
     *
     * The routing itself is unchanged and still is not guessed by name: it
     * mirrors `AbstractContentRewriteService::getPromptOverrideKey()` /
     * `getSeoPromptOverrideKey()`, where every content-rewrite override rides
     * `rewrite.field_rewrite` and every SEO override rides `rewrite.seo_field`.
     * Keys with no template — the admin/customer assistant prompts, which live in
     * phasea AGENTS rather than the prompt catalog, and the generic
     * content_generation/seo_generation keys that no live surface routes —
     * resolve to null and fall back to the explanatory placeholder, exactly as
     * the old `default => null` arm did.
     */

    /**
     * Container key under which the fetched catalog is memoised for the current
     * request, so the base-prompt and preview columns share one phasea read
     * instead of two. Wrapped in an array so a null (phasea-down) result is still
     * cached — `Container::bound()` treats a bare null instance as unbound.
     */
    private const CATALOG_CACHE_KEY = 'ai_prompt_override.catalog';

    /**
     * Container key for the request-scoped override-examples memo (48-20).
     * Separate from the catalog memo because the two columns are rendered
     * independently and either may be the first to ask.
     */
    private const EXAMPLES_CACHE_KEY = 'ai_prompt_override.override_examples';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                self::formColumn(),
                self::basePromptColumn(),
                self::previewColumn(),
            ]);
    }

    /**
     * Left column: Form fields (30% ~ 4 columns).
     */
    private static function formColumn(): Section
    {
        return Section::make(__('ai_prompt_override.section_configuration'))
            ->description(__('ai_prompt_override.section_configuration_description'))
            ->schema([
                Select::make('prompt_key')
                    ->label(__('ai_prompt_override.field.prompt_key'))
                    ->options(AIPromptOverride::availablePromptKeys())
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(__('ai_prompt_override.help.prompt_key')),

                Radio::make('change_type')
                    ->label(__('ai_prompt_override.field.change_type'))
                    ->options([
                        'append' => __('ai_prompt_override.change_type.append'),
                        'prepend' => __('ai_prompt_override.change_type.prepend'),
                        'replace_section' => __('ai_prompt_override.change_type.replace_section'),
                    ])
                    ->descriptions([
                        'append' => __('ai_prompt_override.change_type_description.append'),
                        'prepend' => __('ai_prompt_override.change_type_description.prepend'),
                        'replace_section' => __('ai_prompt_override.change_type_description.replace_section'),
                    ])
                    ->default('append')
                    ->required()
                    ->live(),

                Textarea::make('override_content')
                    ->label(__('ai_prompt_override.field.override_content'))
                    ->placeholder(__('ai_prompt_override.placeholder.override_content'))
                    ->helperText(__('ai_prompt_override.help.override_content'))
                    ->required()
                    ->rows(8)
                    ->live(debounce: 500),

                TextInput::make('description')
                    ->label(__('ai_prompt_override.field.description'))
                    ->placeholder(__('ai_prompt_override.placeholder.description'))
                    ->maxLength(255),

                Toggle::make('enabled')
                    ->label(__('ai_prompt_override.field.enabled'))
                    ->helperText(__('ai_prompt_override.help.enabled'))
                    ->default(true),
            ])
            ->columnSpan(4);
    }

    /**
     * Middle column: Base prompt + examples (35% ~ 4 columns).
     */
    private static function basePromptColumn(): Section
    {
        return Section::make(__('ai_prompt_override.section_base_prompt'))
            ->description(__('ai_prompt_override.section_base_prompt_description'))
            ->schema([
                TextEntry::make('base_prompt_display')
                    ->hiddenLabel()
                    ->state(function (Get $get): HtmlString {
                        $promptKey = $get('prompt_key');

                        if (!$promptKey) {
                            return self::placeholder(__('ai_prompt_override.select_prompt_key'));
                        }

                        $basePrompt = self::resolveBasePrompt($promptKey);

                        if ($basePrompt === null) {
                            return self::placeholder(__('The base prompt could not be loaded from the AI service. You can still write and save your override.'));
                        }

                        $escapedPrompt = nl2br(e($basePrompt));

                        return new HtmlString(
                            '<div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4 font-mono text-xs overflow-auto max-h-96">'.
                            '<pre class="whitespace-pre-wrap break-words">'.$escapedPrompt.'</pre>'.
                            '</div>'
                        );
                    }),

                TextEntry::make('examples_display')
                    ->label(__('ai_prompt_override.section_examples'))
                    ->state(function (Get $get): HtmlString {
                        $promptKey = $get('prompt_key');

                        if (!$promptKey) {
                            return new HtmlString('');
                        }

                        $examples = self::resolveExamples($promptKey);

                        if (empty($examples)) {
                            return new HtmlString(
                                '<div class="text-gray-400 dark:text-gray-500 italic py-2">'.
                                __('ai_prompt_override.no_examples').
                                '</div>'
                            );
                        }

                        $html = '<div class="space-y-3 mt-4">';

                        foreach ($examples as $example) {
                            $typeColor = match ($example['type']) {
                                'prepend' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                'replace_section' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
                            };

                            $escapedTitle = e($example['title']);
                            $escapedContent = e($example['content']);
                            $typeLabel = __('ai_prompt_override.change_type.'.$example['type']);

                            $html .= <<<HTML
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{$escapedTitle}</span>
                                        <span class="px-2 py-0.5 text-xs rounded {$typeColor}">{$typeLabel}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400 font-mono bg-gray-50 dark:bg-gray-900 p-2 rounded whitespace-pre-wrap break-words cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                                         x-data
                                         x-on:click="navigator.clipboard.writeText(`{$escapedContent}`); \$dispatch('notify', {title: '{$escapedTitle}'})">
                                        {$escapedContent}
                                    </div>
                                </div>
                            HTML;
                        }

                        $html .= '</div>';

                        return new HtmlString($html);
                    }),
            ])
            ->columnSpan(4);
    }

    /**
     * Right column: Live preview (35% ~ 4 columns).
     */
    private static function previewColumn(): Section
    {
        return Section::make(__('ai_prompt_override.section_preview'))
            ->description(__('ai_prompt_override.section_preview_description'))
            ->schema([
                TextEntry::make('preview_display')
                    ->hiddenLabel()
                    ->state(function (Get $get): HtmlString {
                        $promptKey = $get('prompt_key');
                        $overrideContent = $get('override_content');
                        $changeType = $get('change_type') ?? 'append';

                        if (!$promptKey) {
                            return self::placeholder(__('ai_prompt_override.select_prompt_key'));
                        }

                        $basePrompt = self::resolveBasePrompt($promptKey);

                        if ($basePrompt === null) {
                            return self::placeholder(__('The base prompt could not be loaded from the AI service. You can still write and save your override.'));
                        }

                        if (empty($overrideContent)) {
                            $escapedPrompt = nl2br(e($basePrompt));

                            return new HtmlString(
                                '<div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4 font-mono text-xs overflow-auto max-h-96">'.
                                '<pre class="whitespace-pre-wrap break-words">'.$escapedPrompt.'</pre>'.
                                '</div>'
                            );
                        }

                        // Build preview with override applied
                        $preview = self::buildPreviewWithHighlight($basePrompt, $overrideContent, $changeType);

                        return new HtmlString(
                            '<div class="mb-2 text-xs text-amber-600 dark:text-amber-400">'.
                            __('ai_prompt_override.override_highlighted').
                            '</div>'.
                            '<div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4 font-mono text-xs overflow-auto max-h-96">'.
                            $preview.
                            '</div>'
                        );
                    }),
            ])
            ->columnSpan(4);
    }

    /**
     * Render an italic placeholder card. Used both when no key is chosen and
     * when the base prompt cannot be loaded — a form that shows no preview is a
     * strictly better outcome than one that fatals because phasea is down.
     */
    private static function placeholder(string $message): HtmlString
    {
        return new HtmlString(
            '<div class="text-gray-400 dark:text-gray-500 italic py-4">'.
            e($message).
            '</div>'
        );
    }

    /**
     * Resolve the base prompt an override is written against by reading it from
     * phasea's read-only catalog (v2.6 design §2.5, phase 48) — the host no
     * longer keeps a local copy. Returns null (→ placeholder) when the override
     * key has no catalog counterpart or when phasea cannot be reached.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * IT READS `base_preview`, NOT `system_prompt` (plan 48-21, finding F2).
     *
     * `system_prompt` is the template's PARAMETERLESS constant. Both templates
     * this form maps — `rewrite.field_rewrite` for the eight content keys and
     * `rewrite.seo_field` for the four SEO ones — compose their system half per
     * call, so that constant is the EMPTY STRING for both. Every one of the twelve
     * mapped keys therefore fell through the `!== ''` check below and rendered the
     * "could not be loaded" placeholder: the form's base-prompt preview was broken
     * for exactly the surfaces it covers, and had been since 48-09 rehomed it.
     * That plan's only success test asserted against a fabricated catalog payload,
     * so it could not see it — which is why the test for this now runs the REAL
     * phasea response (`tests/Fixtures/ai-contract/prompt-templates.json`).
     *
     * `base_preview` is phasea rendering the base from the template's own
     * representative parameters. `system_prompt` remains the fallback so a phasea
     * that predates the field still yields the constant rather than nothing.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private static function resolveBasePrompt(?string $promptKey): ?string
    {
        if (!$promptKey) {
            return null;
        }

        $templateKey = app(PromptKeyRegistry::class)->templateFor($promptKey);

        if ($templateKey === null) {
            return null;
        }

        $catalog = self::catalog();

        if ($catalog === null) {
            return null;
        }

        $row = $catalog[$templateKey] ?? null;

        if (!is_array($row)) {
            return null;
        }

        foreach (['base_preview', 'system_prompt'] as $field) {
            $value = $row[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve the worked override examples for a key by reading them from
     * phasea (v2.6 design §2.5, plan 48-20) — the host no longer authors them.
     *
     * They arrive on the SAME catalog read the base prompt above uses, as
     * `meta.override_examples`. ANY failure degrades to an empty list and the
     * form's "no examples" state: an admin page must not fatal because the AI
     * service is down (the 48-09 rule this column now shares).
     *
     * Each row is shape-checked because it crosses the wire: a malformed entry
     * must vanish, never reach `e($example['title'])` as a missing index.
     *
     * @return array<int, array{title: string, content: string, type: string}>
     */
    private static function resolveExamples(?string $promptKey): array
    {
        if (!$promptKey) {
            return [];
        }

        $forKey = self::examples()[$promptKey] ?? null;

        if (!is_array($forKey)) {
            return [];
        }

        $rows = [];

        foreach ($forKey as $example) {
            if (!is_array($example)) {
                continue;
            }

            $title = $example['title'] ?? null;
            $content = $example['content'] ?? null;
            $type = $example['type'] ?? null;

            if (!is_string($title) || !is_string($content) || !is_string($type)) {
                continue;
            }

            $rows[] = ['title' => $title, 'content' => $content, 'type' => $type];
        }

        return $rows;
    }

    /**
     * Fetch phasea's override examples once per request. Memoised on the same
     * request-scoped principle as the catalog above; wrapped in an array so an
     * empty (phasea-down) result is still cached rather than re-fetched for
     * every re-render of a `->live()` field.
     *
     * @return array<string, mixed>
     */
    private static function examples(): array
    {
        $container = app();

        if ($container->bound(self::EXAMPLES_CACHE_KEY)) {
            /** @var array{examples: array<string, mixed>} $cached */
            $cached = $container->make(self::EXAMPLES_CACHE_KEY);

            return $cached['examples'];
        }

        try {
            $examples = app(AiClient::class)->listPromptOverrideExamples();
        } catch (\Throwable) {
            $examples = [];
        }

        $container->instance(self::EXAMPLES_CACHE_KEY, ['examples' => $examples]);

        return $examples;
    }

    /**
     * Fetch phasea's prompt catalog once per request, indexed by template key.
     * ANY failure (phasea unreachable, auth, malformed body) is swallowed to a
     * null result so an admin page never fatals on an AI-service outage.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function catalog(): ?array
    {
        $container = app();

        if ($container->bound(self::CATALOG_CACHE_KEY)) {
            /** @var array{catalog: array<string, array<string, mixed>>|null} $cached */
            $cached = $container->make(self::CATALOG_CACHE_KEY);

            return $cached['catalog'];
        }

        $catalog = self::fetchCatalog();

        $container->instance(self::CATALOG_CACHE_KEY, ['catalog' => $catalog]);

        return $catalog;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private static function fetchCatalog(): ?array
    {
        try {
            $templates = app(AiClient::class)->listPromptTemplates();
        } catch (\Throwable) {
            return null;
        }

        // Index the catalog by template key. The contract guarantees a `key` on
        // every row, so a plain re-key is safe; any transport failure has already
        // been swallowed to null above.
        return array_column($templates, null, 'key');
    }

    /**
     * Build preview HTML with override content highlighted.
     */
    private static function buildPreviewWithHighlight(string $basePrompt, string $overrideContent, string $changeType): string
    {
        $escapedBase = e($basePrompt);
        $escapedOverride = e($overrideContent);

        $highlightClass = 'bg-amber-100 dark:bg-amber-900/50 border-l-4 border-amber-400 pl-2 py-1';

        $overrideBlock = '<div class="'.$highlightClass.' my-2 whitespace-pre-wrap break-words">'.$escapedOverride.'</div>';

        return match ($changeType) {
            'prepend' => '<pre class="whitespace-pre-wrap break-words">'.$overrideBlock."\n\n".$escapedBase.'</pre>',
            'replace_section' => '<pre class="whitespace-pre-wrap break-words">'.$escapedBase."\n\n".'<span class="text-amber-600 dark:text-amber-400 font-bold">['.e(__('ai_prompt_override.change_type.replace_section')).']</span>'."\n".$overrideBlock.'</pre>',
            default => '<pre class="whitespace-pre-wrap break-words">'.$escapedBase."\n\n".$overrideBlock.'</pre>',
        };
    }
}
