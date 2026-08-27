<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Resources\AIPromptOverride\Pages;

use Core45\AiCore\Filament\Resources\AIPromptOverride\AIPromptOverrideResource;
use Core45\AiCore\Services\TenantAiSettingsSyncService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAIPromptOverrides extends ListRecords
{
    protected static string $resource = AIPromptOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Sync-status surface (Phase 34 Half B, audit B3): the outcome of the
     * last prompt-override push to the AI service, so drift between the
     * host's set and phasea's copy is visible instead of silent.
     */
    public function getSubheading(): ?string
    {
        $status = app(TenantAiSettingsSyncService::class)->status();

        if ($status['last_sync_error'] !== null) {
            return __('AI service sync failed:').' '.$status['last_sync_error'];
        }

        if ($status['last_synced_at'] !== null) {
            return __('Synced to AI service:').' '.$status['last_synced_at'];
        }

        return __('Not yet synced to the AI service.');
    }
}
