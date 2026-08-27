<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride;

use BackedEnum;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Pages\CreateAIPromptOverride;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Pages\EditAIPromptOverride;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Pages\ListAIPromptOverrides;
use Core45\AiCore\Filament\Resources\AIPromptOverride\Tables\AIPromptOverrideTable;
use Core45\AiCore\Models\AIPromptOverride;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AIPromptOverrideResource extends Resource
{
    protected static ?string $model = AIPromptOverride::class;

    protected static ?string $slug = 'ai-prompt-overrides';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $recordTitleAttribute = 'prompt_key';

    protected static ?int $navigationSort = 110;

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
     * `canViewAny()`, which resolves the policy that ships in the package's own
     * namespace so Laravel's auto-discovery can find it.
     *
     * Filament allows when no policy is discoverable, so a consumer app that
     * ships neither policies nor permissions is unaffected: the flag remains
     * the only gate for them.
     *
     * Re-applied when 00bb's Filament tree was merged in. That branch had
     * reverted this to the flag-only check, which would have silently dropped
     * the permission gate on all four resources.
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
        return __('ai_prompt_override.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('ai_prompt_override.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_prompt_override.model_plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        // Form is handled directly in Create/Edit pages for 3-column layout
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AIPromptOverrideTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAIPromptOverrides::route('/'),
            'create' => CreateAIPromptOverride::route('/create'),
            'edit' => EditAIPromptOverride::route('/{record}/edit'),
        ];
    }
}
