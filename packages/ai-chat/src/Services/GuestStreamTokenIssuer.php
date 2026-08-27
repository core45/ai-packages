<?php

declare(strict_types=1);

namespace Core45\AiChat\Services;

use Core45\AiCore\Contracts\IssuesGuestStreamTokens;

/**
 * Issues the host-brokered guest stream token (10-14 Open Q5, T-10-14-02).
 *
 * Signs sha256("guest-stream:{customer_id}:{host_conversation_id}:{expires}")
 * with the shared per-tenant content-sync HMAC secret (AI_CONTENT_SYNC_SECRET,
 * already provisioned in phasea's tenant_settings for BRIDGE-06), scoping the
 * guest stream authorization to a single tenant+conversation with a short TTL.
 * phasea verifies it in GuestStreamTokenVerifier before serving guest streams.
 */
class GuestStreamTokenIssuer implements IssuesGuestStreamTokens
{
    private const PURPOSE = 'guest-stream';

    public const TTL_SECONDS = 120;

    /**
     * @return array{token: string, expires: int}
     */
    public function issue(string $customerId, string $hostConversationId): array
    {
        $secret = config('ai.content_sync.secret');

        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException(
                'AI_CONTENT_SYNC_SECRET must be configured to broker guest stream tokens.',
            );
        }

        $expires = time() + self::TTL_SECONDS;

        return [
            'token' => hash_hmac(
                'sha256',
                self::PURPOSE.':'.$customerId.':'.$hostConversationId.':'.$expires,
                $secret,
            ),
            'expires' => $expires,
        ];
    }
}
