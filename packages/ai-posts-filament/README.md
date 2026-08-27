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

Five self-registering resources, for models this package family owns. They
appear with no wiring at all:

| Resource | Model |
| --- | --- |
| Pipeline Runs (`BlogPostPipelineRunResource`) | `BlogPostPipelineRun` |
| External Links (`BlogExternalLinkResource`) | `BlogExternalLink` |
| Topic Batches (`BlogTopicBatchResource`) | `BlogTopicBatch` |
| Topic Profiles (`BlogTopicProfileResource`) | `BlogTopicProfile` |
| Content Modules (`ContentModuleResource`) | `ContentModule` |

They land in one navigation group. `ai-posts.filament.navigation_group` names
it; leaving it unset falls back to `ai.filament.navigation_group`, so an app
that has already named an AI group gets the blog screens under it too.

Set `ai-posts.filament.post_resource` to the host's own post resource class to
restore the cross-links from these screens to it. `Post` is a host model reached
through `ai.models`, so no package can ship a resource for it — an unset key
drops those links rather than rendering dead ones.

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

## Wiring into a host resource

Three call sites, copy-pasteable into a fresh host's own `PostResource`.
`AiPostsActions` and `AiCoreActions` cannot inject themselves into a
host-owned resource — Filament resets `recordActions`/rebuilds the table
before the resource's own `table()` method runs — so the host must spread
their factories in explicitly. See the docblocks on
`Core45\AiPosts\Filament\Actions\AiPostsActions` and
`Core45\AiCore\Filament\Actions\AiCoreActions` for why.

### 1. Table toolbar bulk actions

`AiPostsActions::forPostsTableToolbar()` must be spread **before**
`AiCoreActions::forTableToolbar('post')` — the order is deliberate, not
cosmetic (see `AiPostsActions::forPostsTableToolbar()`'s docblock).

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Tables;

use Core45\AiCore\Filament\Actions\AiCoreActions;
use Core45\AiPosts\Filament\Actions\AiPostsActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Table;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // ...
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ...AiPostsActions::forPostsTableToolbar(),
                    ...AiCoreActions::forTableToolbar('post'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

`AiCoreActions::forTableToolbar(string $modelKey)` takes the `config('ai.models')`
key naming the records the table lists (`'post'` here) — it is generic across
every host model, not blog-specific.

### 2. Edit page header actions

`AiPostsActions::forPostEditHeader()` is deliberately **one** factory, not an
ai-core spread plus an ai-posts spread, because the actions interleave in a
specific on-screen order (see its docblock).

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use Core45\AiPosts\Filament\Actions\AiPostsActions;
use Core45\AiPosts\Filament\Concerns\HandlesTranslationOnSave;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    use HandlesTranslationOnSave;

    protected static string $resource = PostResource::class;

    protected function afterSave(): void
    {
        $this->checkTranslationsAfterSave();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->translateMissingAction(),
            ...AiPostsActions::forPostEditHeader(),
            DeleteAction::make(),
        ];
    }
}
```

`AiPostsActions::forPostEditHeader()` returns, in on-screen order:
`ManagePostPipelineAction`, `CheckContentSimilarityAction`,
`CheckAiDetectionAction::make()`, `CheckAiDetectionAction::makeViewResults()`,
`EnrichInternalLinksAction`, `RunSeoAnalysisAction`, `ImproveSeoAction`,
`GeneratePostPhotosAction` — the first and the last two are ai-posts's own;
the rest come from `ai-core-filament`.

### 3. `HandlesTranslationOnSave` trait

Used above via `use HandlesTranslationOnSave;` on the Edit page. It requires
nothing beyond the host's `laravellocalization.supportedLocales` config and a
record from `getRecord()` whose translations respond to `translate($locale)`
(Astrotomic `Translatable`) — it dispatches `Jobs\TranslatePostToLocale` per
missing locale and acquires a `Core45\AiCore\Models\TranslationLock` first, so
a save cannot race a translation already in flight for the same locale.

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
