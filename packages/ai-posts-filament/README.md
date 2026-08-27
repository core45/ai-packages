# core45/ai-posts-filament

Filament UI for [`core45/ai-posts`](../ai-posts).

```bash
composer require core45/ai-posts-filament
```

Installing this pulls `core45/ai-posts` with it. The dependency only ever
points this way — no domain package requires a UI package — which is what
lets `core45/ai-posts` install into an app with no Filament, or one on a
different panel. It also requires `core45/ai-core-filament`, because several
of its actions build directly on ai-core's Filament actions (see below).

## What you get

One self-registering resource, for a model this package owns:

| Resource | Model |
| --- | --- |
| Pipeline Runs (`BlogPostPipelineRunResource`) | `BlogPostPipelineRun` |

One standalone page, reachable via `ManagePostPipelineAction` rather than
resource sub-navigation:

| Page | Purpose |
| --- | --- |
| `ManagePostPipeline` | AI-generation screen for a single post's pipeline run |

Actions meant to be spread into a host-owned blog post resource's form,
infolist, or table — a package cannot inject actions into a host resource
directly, so the host opts in explicitly (see `AiPostsActions`'s docblock for
why):

| Action | Purpose |
| --- | --- |
| `AiPostsActions` | Bundles the actions below behind a single opt-in surface |
| `AiRewriteContentAction` | AI content rewrite/generation for a post, with image generation |
| `EnrichInternalLinksAction` | Queues `EnrichPostLinksJob` per locale |
| `GeneratePostPhotosAction` | AI photo generation for a post |
| `ManagePostPipelineAction` | Navigation link to the `ManagePostPipeline` page |

A `Concerns\HandlesTranslationOnSave` trait a resource's Edit page can use to
detect missing translations on save and offer an action to dispatch AI
translation jobs.

They appear with no wiring. `AiPostsFilamentServiceProvider` registers
`AiPostsPlugin` on your panels via `Panel::configureUsing()`.

To restrict which panels get it, set `ai.filament.panels` — the same key
`ai-core-filament` reads, so both plugins are always configured together:

```php
'filament' => [
    'panels' => null,      // every panel (default)
    // 'panels' => ['admin'],  // only these
    // 'panels' => [],         // none; register AiPostsPlugin::make() yourself
],
```

An empty array means *none*, not *unset* — opting out is honoured.

## No operator-notification binding here

Unlike `ai-core-filament`, this package does not bind `NotifiesOperator`.
`ai-core-filament` already upgrades it to `FilamentOperatorNotifier`, and
`ai-posts`'s jobs reuse that binding — there is nothing left for this
package to rebind.

## Namespace

`Core45\AiPosts\Filament\` — the same namespace the classes had inside
`ai-posts`, so nothing referencing `AiPostsPlugin` or a resource class needs
to change. This is the layout Filament itself uses, where `Filament\Tables\`
ships from `filament/tables`.
