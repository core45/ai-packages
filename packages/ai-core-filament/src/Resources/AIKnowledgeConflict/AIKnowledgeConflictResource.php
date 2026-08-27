<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledgeConflict;

use BackedEnum;
use Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Pages\ListAIKnowledgeConflicts;
use Core45\AiCore\Filament\Resources\AIKnowledgeConflict\Tables\AIKnowledgeConflictTable;
use Core45\AiCore\Models\AIKnowledgeConflict;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class AIKnowledgeConflictResource extends Resource
{
    protected static ?string $model = AIKnowledgeConflict::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 102;

    public static function shouldRegisterNavigation(): bool
    {
        return config('ai.assistant.enabled', false);
    }

    /**
     * Access requires BOTH the assistant feature flag and the viewer's
     * permission.
     *
     * The flag alone used to be the whole check, which meant the Shield
     * permissions for this resource were never consulted: any user who could
     * reach the panel at all could open it. `parent::canAccess()` delegates to
     * `canViewAny()`, which resolves the policy that now ships in the package's
     * own namespace so Laravel's auto-discovery can find it.
     *
     * Filament allows when no policy is discoverable, so a consumer app that
     * ships neither policies nor permissions is unaffected: the flag remains
     * the only gate for them.
     */
    public static function canAccess(): bool
    {
        return config('ai.assistant.enabled', false) && parent::canAccess();
    }

    /**
     * Consumer-owned: the consuming app names its own admin navigation group
     * through `ai.filament.navigation_group` — a plain string or an enum case
     * implementing Filament's HasLabel. ai-core defaults to a plain string so
     * it never imports a host enum (this app sets `NavigationGroup::Ai`).
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('ai.filament.navigation_group', 'AI');

        return is_string($group) || $group instanceof UnitEnum ? $group : 'AI';
    }

    public static function getNavigationLabel(): string
    {
        return __('ai_knowledge.conflicts_navigation');
    }

    public static function getModelLabel(): string
    {
        return __('ai_knowledge.conflicts_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_knowledge.conflicts_model_plural_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return Cache::remember('nav-badge-ai-knowledge-conflicts', now()->addMinutes(5), function (): ?string {
            $count = AIKnowledgeConflict::unresolved()->count();

            return $count > 0 ? (string) $count : null;
        });
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return AIKnowledgeConflictTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAIKnowledgeConflicts::route('/'),
        ];
    }
}
