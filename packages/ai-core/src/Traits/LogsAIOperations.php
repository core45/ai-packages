<?php

namespace Core45\AiCore\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait LogsAIOperations
{
    /** @var array<int, array{sql: string, bindings: array<mixed>, time_ms: float}> */
    protected array $capturedQueries = [];

    protected bool $queryLoggingActive = false;

    protected function isDebugEnabled(): bool
    {
        return (bool) config('ai.assistant.debug', false);
    }

    protected function isVerboseLogging(): bool
    {
        return (bool) config('ai.assistant.debug_verbose', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDebug(string $operation, array $context = []): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        Log::channel('ai')->debug("[AI Assistant] {$operation}", array_merge([
            'service' => static::class,
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function logRequest(string $operation, array $params): void
    {
        $this->logDebug("{$operation} - Request", [
            'params' => $this->sanitizeForLog($params),
        ]);
    }

    /**
     * @param  array<string, int>|null  $usage
     */
    protected function logResponse(string $operation, mixed $response, ?array $usage = null): void
    {
        $context = [
            'response_type' => gettype($response),
        ];

        if (is_string($response)) {
            $context['response_length'] = strlen($response);
            // Full response if verbose, otherwise truncate
            if ($this->isVerboseLogging()) {
                $context['response_full'] = $response;
            } else {
                $context['response_preview'] = substr($response, 0, 500).(strlen($response) > 500 ? '...' : '');
            }
        } elseif (is_array($response)) {
            $context['response'] = $this->sanitizeForLog($response);
        }

        if ($usage) {
            $context['usage'] = $usage;
        }

        $this->logDebug("{$operation} - Response", $context);
    }

    /**
     * Log the full LLM communication (system prompt, messages, response).
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, int>  $usage
     */
    protected function logLLMCommunication(
        string $operation,
        string $systemPrompt,
        array $messages,
        string $response,
        array $usage,
        ?float $estimatedCost = null
    ): void {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $context = [
            'system_prompt_length' => strlen($systemPrompt),
            'message_count' => count($messages),
            'response_length' => strlen($response),
            'usage' => $usage,
        ];

        if ($estimatedCost !== null) {
            $context['estimated_cost_usd'] = round($estimatedCost, 6);
        }

        // Include full content only in verbose mode
        if ($this->isVerboseLogging()) {
            $context['system_prompt'] = $systemPrompt;
            $context['messages'] = array_map(function ($msg) {
                if (is_object($msg) && method_exists($msg, 'toArray')) {
                    return $msg->toArray();
                }
                if (is_object($msg)) {
                    return [
                        'class' => get_class($msg),
                        'content' => property_exists($msg, 'content') ? $msg->content : '[no content property]',
                    ];
                }

                return $msg;
            }, $messages);
            $context['response_full'] = $response;
        } else {
            $context['system_prompt_preview'] = substr($systemPrompt, 0, 300).'...';
            $context['response_preview'] = substr($response, 0, 500).(strlen($response) > 500 ? '...' : '');
        }

        $this->logDebug("{$operation} - LLM Communication", $context);
    }

    /**
     * Log tool call made by the LLM.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function logToolCall(string $toolName, array $arguments, mixed $result): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $context = [
            'tool' => $toolName,
            'arguments' => $this->sanitizeForLog($arguments),
        ];

        if (is_string($result)) {
            $context['result_length'] = strlen($result);
            if ($this->isVerboseLogging()) {
                $context['result'] = $result;
            } else {
                $context['result_preview'] = substr($result, 0, 500).(strlen($result) > 500 ? '...' : '');
            }
        } else {
            $context['result'] = $this->sanitizeForLog(is_array($result) ? $result : ['value' => $result]);
        }

        $this->logDebug('Tool Call', $context);
    }

    /**
     * Log knowledge injection into prompts.
     */
    protected function logKnowledgeInjection(string $operation, int $criticalCount, int $contextualCount, string $formattedKnowledge): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $context = [
            'critical_knowledge_count' => $criticalCount,
            'contextual_knowledge_count' => $contextualCount,
            'total_knowledge_length' => strlen($formattedKnowledge),
        ];

        if ($this->isVerboseLogging()) {
            $context['knowledge_content'] = $formattedKnowledge;
        }

        $this->logDebug("{$operation} - Knowledge Injection", $context);
    }

    /**
     * Log estimated cost for an operation.
     */
    protected function logCost(string $operation, string $provider, string $model, int $promptTokens, int $completionTokens): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);

        $this->logDebug("{$operation} - Cost Estimate", [
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'estimated_cost_usd' => round($cost, 6),
        ]);
    }

    /**
     * Calculate estimated cost based on provider and model.
     */
    protected function calculateEstimatedCost(string $provider, string $model, int $promptTokens, int $completionTokens): float
    {
        // Default pricing per 1M tokens
        $defaultPricing = ['input' => 1.00, 'output' => 3.00];

        // Cost per 1M tokens (approximate, as of late 2024)
        /** @var array<string, array<string, array{input: float, output: float}>> $providerModels */
        $providerModels = [
            'anthropic' => [
                'claude-opus-4-5-20251101' => ['input' => 15.00, 'output' => 75.00],
                'claude-opus-4-20250514' => ['input' => 15.00, 'output' => 75.00],
                'claude-sonnet-4-20250514' => ['input' => 3.00, 'output' => 15.00],
                'claude-haiku-4-20250414' => ['input' => 0.80, 'output' => 4.00],
            ],
            'openai' => [
                'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
                'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
                'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
                'o3-mini' => ['input' => 1.10, 'output' => 4.40],
            ],
            'gemini' => [
                'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
                'gemini-2.0-flash-lite' => ['input' => 0.075, 'output' => 0.30],
                'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
            ],
        ];

        // Get model pricing or fall back to default
        $modelPricing = $providerModels[$provider][$model] ?? $defaultPricing;

        return (($promptTokens / 1_000_000) * $modelPricing['input'])
             + (($completionTokens / 1_000_000) * $modelPricing['output']);
    }

    protected function logError(string $operation, \Throwable $e): void
    {
        if (!config('ai.assistant.debug', false)) {
            return;
        }

        Log::channel('ai')->error("[AI Assistant] {$operation} - Error", [
            'service' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Start capturing database queries for the current operation.
     */
    protected function startQueryLogging(): void
    {
        if (!config('ai.assistant.debug', false)) {
            return;
        }

        $this->capturedQueries = [];
        $this->queryLoggingActive = true;

        DB::listen(function ($query) {
            if ($this->queryLoggingActive) {
                $this->capturedQueries[] = [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                ];
            }
        });
    }

    /**
     * Stop capturing queries and log them.
     */
    protected function stopQueryLogging(string $operation): void
    {
        if (!config('ai.assistant.debug', false)) {
            return;
        }

        $this->queryLoggingActive = false;

        if (!empty($this->capturedQueries)) {
            $totalTime = array_sum(array_column($this->capturedQueries, 'time_ms'));

            $this->logDebug("{$operation} - Database Queries", [
                'query_count' => count($this->capturedQueries),
                'total_time_ms' => round($totalTime, 2),
                'queries' => array_map(function ($query) {
                    return [
                        'sql' => $this->interpolateQuery($query['sql'], $query['bindings']),
                        'time_ms' => $query['time_ms'],
                    ];
                }, $this->capturedQueries),
            ]);
        }

        $this->capturedQueries = [];
    }

    /**
     * Interpolate bindings into SQL query for readability.
     *
     * @param  array<int, mixed>  $bindings
     */
    protected function interpolateQuery(string $sql, array $bindings): string
    {
        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'".addslashes((string) $binding)."'";
            $sql = preg_replace('/\?/', (string) $value, $sql, 1);
        }

        return $sql;
    }

    /**
     * Log an operation with automatic query capture.
     */
    protected function withQueryLogging(string $operation, callable $callback): mixed
    {
        $this->startQueryLogging();
        $this->logDebug("{$operation} - Started");

        $startTime = microtime(true);

        try {
            $result = $callback();

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logDebug("{$operation} - Completed", ['duration_ms' => $duration]);

            return $result;
        } catch (\Throwable $e) {
            $this->logError($operation, $e);
            throw $e;
        } finally {
            $this->stopQueryLogging($operation);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeForLog(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Skip sensitive keys
            if (in_array($key, ['api_key', 'password', 'secret', 'token'], true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForLog($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000).'... [truncated]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
