<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Chat;

use Core45\AiCore\Contracts\IssuesGuestStreamTokens;
use RuntimeException;

/**
 * The default for `IssuesGuestStreamTokens`, and the one default here that is
 * deliberately NOT a null object.
 *
 * Every other unbound contract in this package got a no-op or an empty result,
 * because "this application does not do that" is a legitimate answer for
 * keyphrase storage, citation enrichment or mandatory words — the feature is
 * simply off.
 *
 * Guest stream tokens are different. `RemoteAiClient` resolves this on the
 * anonymous-chat path (lines 297 and 338) whenever the identity is a guest, and
 * the token it returns is a **credential forwarded to the phasea service**. A
 * null object would have to invent one, and phasea would reject it — turning a
 * missing binding into an authentication failure several systems away from its
 * cause. There is no honest no-op for issuing a credential.
 *
 * So this exists purely to replace one error message with a better one. Without
 * any binding the host sees:
 *
 *     BindingResolutionException: Target [Core45\AiCore\Contracts\IssuesGuestStreamTokens]
 *     is not instantiable.
 *
 * which names the interface and nothing else — not that guest chat triggered it,
 * not that anonymous chat is optional, not what to do. The exception below says
 * all three.
 *
 * An application that does not serve anonymous chat never reaches this class.
 */
final class UnconfiguredGuestStreamTokenIssuer implements IssuesGuestStreamTokens
{
    /**
     * @return array{token: string, expires: string}
     */
    public function issue(string $customerId, string $conversationId): array
    {
        throw new RuntimeException(sprintf(
            'Anonymous chat requires a %s implementation, and this application has not bound one. '
            .'The token it returns is a credential sent to the AI service, so there is no safe default: '
            .'a placeholder would be rejected downstream and the real cause would be lost. '
            .'Bind your own issuer in a service provider, or do not expose the guest chat surface.',
            IssuesGuestStreamTokens::class,
        ));
    }
}
