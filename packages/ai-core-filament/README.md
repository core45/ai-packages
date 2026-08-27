# core45/ai-core-filament

Filament UI for [`core45/ai-core`](../ai-core).

```bash
composer require core45/ai-core-filament
```

Installing this pulls `core45/ai-core` with it. The dependency only ever points
this way — no domain package requires a UI package — which is what lets
`core45/ai-core` install into an app with no Filament, or one on a different
panel.

## What you get

Four self-registering resources, for models this package family owns:

| Resource | Model |
| --- | --- |
| AI Knowledge | `AILearnedKnowledge` |
| AI Knowledge Conflicts | `AIKnowledgeConflict` (+ stats widget) |
| AI Knowledge Gaps | `AIKnowledgeGap` (+ stats widget) |
| AI Prompt Overrides | `AIPromptOverride` |

They appear with no wiring. `AiCoreFilamentServiceProvider` registers
`AiCorePlugin` on your panels via `Panel::configureUsing()`.

To restrict which panels get it, set `ai.filament.panels`:

```php
'filament' => [
    'panels' => null,      // every panel (default)
    // 'panels' => ['admin'],  // only these
    // 'panels' => [],         // none; register AiCorePlugin::make() yourself
],
```

An empty array means *none*, not *unset* — opting out is honoured.

## Operator notifications

`ai-core` defines `NotifiesOperator`, the seam the AI jobs use to report
progress ("Photos generated", "Pipeline failed"). It binds a logging
implementation by default, because a package that has no panel has nowhere to
put a notification.

This package binds `FilamentOperatorNotifier` over it, so those messages become
Filament database notifications in the panel's bell menu. That is the behaviour
the packages had before the UI was split out; installing this package restores
it, and no application config is involved.

Binding order is `ai-core` (`bindIf`) → this package (`bind`) → your app. An app
that binds its own implementation still wins, because its providers register
last.

## Enum labels: no auto-detection

The enums in `ai-core` and `ai-posts` do **not** implement Filament's `HasLabel`
or `HasColor`. They cannot: those interfaces live in `filament/support`, which
depends on Livewire, and requiring it would put Filament into every domain
package — including `core45/ai-chat`, which otherwise has none.

They keep plain `getLabel()`, `label()` and `color()` methods instead. Filament
detects the interfaces with `instanceof`, so passing an enum class where a label
is expected renders raw values. Be explicit:

```php
// Instead of ->options(BlogPostPipelineStatus::class)
->options(BlogPostPipelineStatus::options())

// Instead of relying on badge auto-detection
->formatStateUsing(fn (AIConflictStatus $state): string => $state->label())
->color(fn (AIConflictStatus $state): string => $state->color())
```

The resources in this package already do this.

## Namespace

`Core45\AiCore\Filament\` — the same namespace the classes had inside `ai-core`,
so nothing referencing `AiCorePlugin` or a resource class needs to change. This
is the layout Filament itself uses, where `Filament\Tables\` ships from
`filament/tables`.
