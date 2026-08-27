<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

/**
 * Brokers short-lived guest stream tokens for guest chat identities
 * (D9-style inversion): the core client needs a token when proxying guest
 * chat turns, but token issuance is consumer-owned (session/auth specifics).
 * The consuming app binds its implementation (the host's
 * GuestStreamTokenIssuer today; ai-chat's once that package ships).
 */
interface IssuesGuestStreamTokens
{
    /**
     * @return array{token: string, expires: string}
     */
    public function issue(string $customerId, string $conversationId): array;
}
