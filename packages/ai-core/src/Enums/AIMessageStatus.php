<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Status of an AI conversation message.
 *
 * Tracks the lifecycle of a message during streaming:
 * - Pending: Message created, waiting for AI processing
 * - Streaming: AI is actively generating the response
 * - Complete: Response finished successfully
 * - Error: An error occurred during processing
 */
enum AIMessageStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Complete = 'complete';
    case Error = 'error';

    /**
     * Get the display label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Streaming => __('Streaming'),
            self::Complete => __('Complete'),
            self::Error => __('Error'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Check if the message is still being processed.
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::Pending, self::Streaming => true,
            self::Complete, self::Error => false,
        };
    }
}
