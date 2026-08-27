<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests\Unit;

use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiRequestRejectedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\Services\LlmProviderErrorClassifier;
use Exception;
use PHPUnit\Framework\TestCase;

class LlmProviderErrorClassifierTest extends TestCase
{
    public function test_provider_unusable_covers_transient_billing_and_auth(): void
    {
        // Transient (instanceof-based classification)
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(AiRateLimitedException::withRetryAfter(30, 'op-1')));
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(AiUnavailableException::forStatus(503, 'op-2')));
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(new Exception('overloaded', 529)));
        // Billing / quota
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(new Exception('Anthropic Error [400]: Your credit balance is too low', 400)));
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(new Exception('insufficient_quota', 429)));
        // Auth
        $this->assertTrue(LlmProviderErrorClassifier::isProviderUnusable(new Exception('authentication_error - invalid api key', 401)));
        // Genuine malformed request → not unusable
        $this->assertFalse(LlmProviderErrorClassifier::isProviderUnusable(new Exception('messages: at least one message is required', 400)));
    }

    public function test_transient_is_narrower_than_unusable(): void
    {
        // Transient: waiting may help (instanceof-based classification)
        $this->assertTrue(LlmProviderErrorClassifier::isTransient(AiRateLimitedException::withRetryAfter(30, 'op-1')));
        $this->assertTrue(LlmProviderErrorClassifier::isTransient(AiUnavailableException::forStatus(503, 'op-2')));
        $this->assertTrue(LlmProviderErrorClassifier::isTransient(new Exception('overloaded', 503)));
        $this->assertTrue(LlmProviderErrorClassifier::isTransient(new Exception('You hit a provider rate limit. Details: []', 0)));

        // NOT transient: waiting won't help — needs a different provider or human action
        $this->assertFalse(LlmProviderErrorClassifier::isTransient(new Exception('Your credit balance is too low', 400)));
        $this->assertFalse(LlmProviderErrorClassifier::isTransient(new Exception('authentication_error', 401)));
        $this->assertFalse(LlmProviderErrorClassifier::isTransient(new Exception('model not_found', 404)));
        $this->assertFalse(LlmProviderErrorClassifier::isTransient(new Exception('messages: field required', 400)));
    }

    public function test_request_rejected_is_permanent_never_transient(): void
    {
        // 16-REVIEW MJ-01: permanent 4xx rejections from the remote AI service
        // arrive as AiRequestRejectedException — NEVER transient, regardless of
        // status. Retrying an over-length prompt (422) or a token misconfig
        // (401/403) can never succeed.
        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertFalse(
                LlmProviderErrorClassifier::isTransient(AiRequestRejectedException::forStatus($status, 'op-r')),
                "status {$status} must not classify transient",
            );
        }
    }

    public function test_request_rejected_provider_unusable_branches_on_status(): void
    {
        // 401/403/404 = THIS provider is unusable (auth/permission/missing
        // model) → a fallback provider may help. 400/422 = the request itself
        // is malformed → no provider fallback can fix it.
        foreach ([401, 403, 404] as $status) {
            $this->assertTrue(
                LlmProviderErrorClassifier::isProviderUnusable(AiRequestRejectedException::forStatus($status, 'op-r')),
                "status {$status} must classify provider-unusable",
            );
        }

        foreach ([400, 422] as $status) {
            $this->assertFalse(
                LlmProviderErrorClassifier::isProviderUnusable(AiRequestRejectedException::forStatus($status, 'op-r')),
                "status {$status} must not classify provider-unusable",
            );
        }
    }
}
