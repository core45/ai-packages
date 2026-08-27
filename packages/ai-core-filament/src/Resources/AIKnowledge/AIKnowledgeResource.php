<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIKnowledge;

use BackedEnum;
use Core45\AiCore\Filament\Resources\AIKnowledge\Pages\CreateAIKnowledge;
use Core45\AiCore\Filament\Resources\AIKnowledge\Pages\EditAIKnowledge;
use Core45\AiCore\Filament\Resources\AIKnowledge\Pages\ListAIKnowledge;
use Core45\AiCore\Filament\Resources\AIKnowledge\Schemas\AIKnowledgeForm;
use Core45\AiCore\Filament\Resources\AIKnowledge\Tables\AIKnowledgeTable;
use Core45\AiCore\Models\AILearnedKnowledge;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AIKnowledgeResource extends Resource
{
    protected static ?string $model = AILearnedKnowledge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 100;

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
        return __('ai-core::ai_knowledge.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('ai-core::ai_knowledge.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai-core::ai_knowledge.model_plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return AIKnowledgeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AIKnowledgeTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAIKnowledge::route('/'),
            'create' => CreateAIKnowledge::route('/create'),
            'edit' => EditAIKnowledge::route('/{record}/edit'),
        ];
    }
}
