<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

/**
 * Host-side localized labels for phasea's C31-3 `tool_call` frames.
 *
 * The host localizes tool activity keyed by tool name (audit Theme B /
 * C31-3); phasea's `friendly_label` frame field is only the fallback shown
 * for tool names the host has no localization for. Labels are plain English
 * strings resolved through the JSON lang files (`lang/{locale}.json`),
 * matching the app's translation convention.
 */
final class FriendlyToolLabels
{
    /**
     * English label per known tool name — each string exists as a key in
     * EVERY `lang/*.json` file (translation-sync rule).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'query_store_data' => 'Searching store data',
        'propose_action' => 'Preparing an action for your confirmation',
        'inject_to_form' => 'Inserting content into the form',
        'seo_correlation' => 'Analyzing SEO signals',
        'search_customer_conversations' => 'Searching customer conversations',
        'product_advisor' => 'Finding product recommendations',
        'customer_account' => 'Checking your account',
    ];

    /**
     * Resolve the localized label for a tool name, falling back to phasea's
     * `friendly_label`, then to the raw tool name.
     */
    public static function resolve(string $toolName, ?string $friendlyLabel = null): string
    {
        $label = self::LABELS[$toolName] ?? null;

        if ($label !== null) {
            return __($label);
        }

        if ($friendlyLabel !== null && $friendlyLabel !== '') {
            return $friendlyLabel;
        }

        return $toolName;
    }
}
