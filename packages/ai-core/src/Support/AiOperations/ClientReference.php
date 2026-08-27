<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\AiOperations;

/**
 * Builds opaque host references for phasea operations (D-27-8).
 *
 * Only the local operation-row identifier crosses the boundary. The echoed
 * value is correlation data, never authorization data, and must never be
 * resolved into an arbitrary model or writable field.
 */
final class ClientReference
{
    public static function forDetection(int $detectionId): string
    {
        return 'v1.d.'.$detectionId;
    }

    public static function forHumanization(int $humanizationId): string
    {
        return 'v1.h.'.$humanizationId;
    }

    public static function isValid(string $reference): bool
    {
        return strlen($reference) <= 255
            && preg_match('/\Av1\.[dh]\.[0-9]{1,20}\z/', $reference) === 1;
    }
}
