<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Core45\AiCore\DTOs\AiTextResult;
use Core45\AiCore\DTOs\ChatRequest;
use Core45\AiCore\DTOs\ChatResponse;
use Core45\AiCore\DTOs\ContentRequest;
use Core45\AiCore\DTOs\ContentResponse;
use Core45\AiCore\DTOs\DetectionRequest;
use Core45\AiCore\DTOs\DetectionResult;
use Core45\AiCore\DTOs\HumanizationRequest;
use Core45\AiCore\DTOs\HumanizationResult;
use Core45\AiCore\DTOs\SearchResult;
use Core45\AiCore\DTOs\SeoResponse;
use Core45\AiCore\DTOs\StreamEvent;
use Core45\AiCore\DTOs\TranslationRequest;
use Core45\AiCore\DTOs\TranslationResponse;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiClientException;

/**
 * Single task-oriented contract for the entire AI layer.
 *
 * `RemoteAiClient` implements this interface and forwards every AI surface to
 * the phasea service. Methods are flat and task-oriented — the existing fluent
 * sub-chains (`AIAssistantService::content()->generateContent()`) are
 * flattened here into explicit single methods, never fluent sub-chains.
 *
 * IDs + plain data + DTOs only — no Eloquent model types appear anywhere in
 * these signatures. `DetectionRequest`/`HumanizationRequest` carry a
 * `modelType` class-string + `modelId` key, never the model instance itself.
 */
interface AiClient
{
    /**
     * Send a single-turn, non-streaming chat message and get a response.
     *
     * @throws AiClientException
     */
    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Send a single-turn, non-streaming CUSTOMER-assistant chat message.
     *
     * Targets the customer-assistant surface (phasea's
     * `POST api/v1/ai/assistant/chat`) — persona, tools, tier rate limiting
     * and usage attribution differ from the generic admin `chat()` surface, so
     * the customer widget's sync fallback must use this method to stay
     * behaviorally identical to its streaming path (audit A3).
     *
     * The customer identity rides `ChatRequest::hostUserId` (required here):
     * a real customer PK string, or the host-brokered `guest:`-prefixed guest
     * identity, mirroring `streamCustomerChat()`.
     *
     * @throws AiClientException
     */
    public function customerChat(ChatRequest $request): ChatResponse;

    /**
     * Stream a chat response, yielding typed `StreamEvent` DTOs.
     *
     * @return \Generator<int, StreamEvent, mixed, void>
     *
     * @throws AiClientException
     */
    public function streamChat(ChatRequest $request): \Generator;

    /**
     * Generate content for a specific field.
     *
     * @throws AiClientException
     */
    public function generateContent(ContentRequest $request): ContentResponse;

    /**
     * Generate SEO content (title, description, keywords).
     *
     * @throws AiClientException
     */
    public function generateSEO(ContentRequest $request): SeoResponse;

    /**
     * Execute a knowledge/RAG query.
     *
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function query(string $query, string $locale, ?int $limit, string $operationId): array;

    /**
     * Preview what an action will do, without executing it.
     *
     * @param  array<string, mixed>  $entityData
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function previewAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId): array;

    /**
     * Execute an action.
     *
     * @param  array<string, mixed>  $entityData
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function executeAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId, bool $confirmed = false): array;

    /**
     * List a user's conversations.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AiClientException
     */
    public function getConversations(int $userId): array;

    /**
     * Create a new conversation.
     *
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function createConversation(int $userId, ?string $title = null): array;

    /**
     * Close/end a conversation.
     *
     * @throws AiClientException
     */
    public function closeConversation(string $conversationId): bool;

    /**
     * Get the available AI schema/capabilities.
     *
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function getSchema(): array;

    /**
     * Check whether AI is enabled for this surface.
     */
    public function isEnabled(): bool;

    /**
     * Translate text between locales.
     *
     * @throws AiClientException
     */
    public function translate(TranslationRequest $request): TranslationResponse;

    /**
     * Rewrite content in a given style/locale.
     *
     * @throws AiClientException
     */
    public function rewriteContent(string $content, string $style, string $locale, string $operationId): string;

    /**
     * Generate a single embedding vector for the given text.
     *
     * @return array<int, float>|null Array of floats, or null on failure
     *
     * @throws AiClientException
     */
    public function embed(string $text): ?array;

    /**
     * Generate embedding vectors for a batch of texts.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>|null>
     *
     * @throws AiClientException
     */
    public function embedBatch(array $texts): array;

    /**
     * Summarize the given text.
     *
     * @throws AiClientException
     */
    public function summarize(string $text, string $operationId): string;

    /**
     * Research keyphrases for a topic.
     *
     * `$contentExcerpt` optionally carries a plain-text excerpt of the page
     * body (service cap 2000 chars — clamped client-side) so the research
     * agent sees real content alongside the topic (phase-33 contract).
     *
     * Returns structured `{keyphrase, reason, estimatedDifficulty}` maps.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AiClientException
     */
    public function keyphraseResearch(string $topic, string $locale, string $operationId, ?string $contentExcerpt = null): array;

    /**
     * Grounded web search (phasea `POST api/v1/ai/search/grounded`, Phase 32).
     *
     * The host does not choose the search vendor — phasea resolves its own
     * default upstream backend server-side (v2.6: the `provider` argument
     * was removed; sending no `provider` field lets phasea decide). If
     * phasea's default vendor is not configured on the service,
     * `AiProviderNotConfiguredException` is thrown (a typed config error —
     * never a silent empty result set).
     *
     * @return list<SearchResult>
     *
     * @throws AiClientException
     */
    public function groundedSearch(string $query, int $maxResults, string $operationId): array;

    /**
     * Generate an image from a PHASEA-OWNED image prompt, sending only
     * parameters.
     *
     * THE ONLY IMAGE-GENERATING METHOD ON THIS CONTRACT (v2.6 design §2.5; the
     * raw `generateImage(string $prompt, …)` twin was deleted by plan 48-21,
     * finding F3). An image prompt is instruction text exactly like a completion
     * prompt, so a contract method that accepts one as a string is the same
     * violation as `completeText()` — and it was worse-guarded: phaseb's
     * architectural fence (`NoNonTranslateCompleteTextCallerTest`) covers
     * `completeText()` only, so a future caller could have authored an image
     * prompt in the host without tripping anything. 48-20's summary nonetheless
     * called `completeText()` the only remaining raw-instruction seam; that claim
     * was false as written, and this deletion is what makes it true.
     *
     * The method could simply be removed because it had ZERO callers in `app/` —
     * every image path (`GeneratePostPhotosJob`, `BlogImageGeneratorService`,
     * `AIMediaGenerationService`) had already migrated to the template form.
     * `NoRawImagePromptSeamTest` pins that it stays gone.
     *
     * It cannot ride `runPrompt()` because that returns text — so phasea's
     * `image/generate` accepts `prompt_template` + `parameters`, and this method
     * posts exactly that. The request body carries NO `prompt` key at all.
     *
     * `$parameters` is TEMPLATE INPUT. `$params` is the transport/gateway map
     * (`style`, `aspect_ratio`, `timeout`); `model` is deliberately NOT forwarded
     * — the host does not choose a model, phasea resolves it from its own config.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function generateImageFromTemplate(string $template, array $parameters, array $params, string $operationId): array;

    /**
     * Generate alt text for an image, resolved server-side from a host media
     * identifier (phasea's `image-analysis` endpoint has no dedicated
     * alt-text field — `analysis` is the closest available approximation).
     *
     * @throws AiClientException
     */
    public function generateAltText(string $mediaId, string $locale, string $operationId): string;

    /**
     * Generic single-turn text completion (system + user prompt -> text).
     *
     * This is the seam that replaces the bespoke local AI client calls across
     * the BlogPipeline/BlogTopics orchestrators (the Phase-8 SEAM-08 contract
     * gaps). `$options` carries the in-process execution parameters so `local`
     * routing reproduces today's exact behavior:
     *   - `provider` (string): AI provider slug (openai, anthropic, ...)
     *   - `model` (string): model name
     *   - `timeout` (int): request timeout in seconds
     *
     * `provider`/`model`/`reasoning_effort` are never forwarded to phasea —
     * the host does not choose a model, provider, or reasoning effort;
     * phasea decides all three server-side. RemoteAiClient::completeText()
     * strips them from `$options` unconditionally before the request.
     *
     * `$surface` is transmitted as the request's `surface` field; every
     * surface posts phasea's single completion route
     * `api/v1/ai/text/complete` (Open Q7: no per-surface completion routes
     * exist, and the per-surface `AI_SURFACE_*_DRIVER` keys were deleted
     * in 13-03).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AiClientException
     */
    public function completeText(string $systemPrompt, string $userPrompt, array $options, AiSurface $surface, string $operationId): AiTextResult;

    /**
     * Run a PHASEA-OWNED prompt by template key, sending only parameters.
     *
     * The v2.6 replacement for `completeText()` (design §2.5): this host may not
     * hold prompt text, so it names a template phasea owns and passes structured
     * data. Every remaining `completeText()` caller that assembles instruction
     * strings is a migration target — do not add new ones.
     *
     * `$parameters` is TEMPLATE INPUT and nothing else — it is the only part of
     * this call serialised into the request body. `$options` is TRANSPORT-ONLY
     * configuration (today: `timeout` in seconds, clamped by `ai.timeout_max`),
     * is NEVER sent to phasea, and must never carry template data. Keeping the
     * two apart is what lets a long-running surface (blog post writing, content
     * rewrite) keep its own timeout budget instead of silently inheriting the
     * 30s `AI_SERVICE_TIMEOUT` default, without opening a second wire field a
     * host could smuggle instructions through.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     *
     * @throws AiClientException
     */
    public function runPrompt(string $template, array $parameters, string $operationId, array $options = []): AiTextResult;

    /**
     * List the phasea-owned prompt templates for DISPLAY (v2.6 design §2.5).
     *
     * The read counterpart of `runPrompt()`: `GET api/v1/ai/prompt/templates`
     * returns each registered template's `key`, `task`, `system_prompt` and
     * `parameter_keys` so an admin UI can show the base prompt an override is
     * written against. It composes nothing, runs no agent, and bills nothing.
     * Returns the unwrapped `data` list.
     *
     * @return array<int, array{key: string, task: string, system_prompt: string, parameter_keys: array<int, string>}>
     *
     * @throws AiClientException
     */
    public function listPromptTemplates(): array;

    /**
     * The prompt-contract CAPABILITIES the phasea service advertises
     * (plan 48-22).
     *
     * The third reader of the SAME free, unbilled `GET api/v1/ai/prompt/templates`
     * call, off its `meta.capabilities` list. It exists because a two-repo wire
     * change (48-21, F5) is only safe in both deploy orders if the host can ask
     * FIRST which shape the service it is talking to understands.
     *
     * SEPARATE FROM `listPromptTemplates()` BY NECESSITY, not preference: that
     * method unwraps `data` and discards `meta`, exactly as
     * `listPromptOverrideExamples()` found in 48-20. The plan asked for the probe
     * to ride the existing method; the existing method cannot carry it.
     *
     * AN OLDER phasea RETURNS NO SUCH KEY, and the empty list that produces is the
     * correct answer: absence of a capability IS absence of the capability.
     *
     * THE DISPOSITION IS RETURNED ALONGSIDE THE LIST (plan 48-23), because three
     * different situations all produce an empty list and an operator deciding
     * whether the transition window may be closed has to tell them apart:
     *
     *   `advertised` — the service answered with a well-formed capability list;
     *   `malformed`  — it answered, but `meta.capabilities` was not a list of
     *                  non-empty strings (a JSON OBJECT such as
     *                  `{"preferred": "…typed_placeholders"}` used to read as
     *                  SUPPORT, because filtering an object's values and
     *                  re-indexing them produces a plausible list);
     *   `probe_failed` — the call itself threw, which the caller maps from the
     *                  exception rather than from a return value.
     *
     * A caller must treat every non-`advertised` outcome, and an `advertised`
     * outcome whose list lacks the capability, as "assume nothing".
     *
     * `release` carries whatever build identifier the service publishes
     * (`meta.release`), or `'unknown'`. It is a LOG FIELD, never an input: a
     * version string that steers behaviour is API version negotiation, which
     * plan 48-23 puts explicitly out of scope.
     *
     * @return array{disposition: 'advertised'|'malformed', capabilities: list<string>, release: string}
     *
     * @throws AiClientException
     */
    public function listServiceCapabilities(): array;

    /**
     * List the phasea-owned tenant prompt-override EXAMPLES for DISPLAY
     * (v2.6 design §2.5, plan 48-20).
     *
     * Reads the SAME `GET api/v1/ai/prompt/templates` call as
     * `listPromptTemplates()` and returns its `meta.override_examples` map,
     * keyed by override key (`admin_system`, `customer_system`, …). These are
     * worked instruction blocks an admin copies into an override; phaseb used
     * to author them in `App\Services\AI\Prompts\PromptExamples`, the second
     * host prompt library, which plan 48-20 deleted. Displaying them is a read;
     * authoring them was not.
     *
     * @return array<string, array<int, array{title: string, content: string, type: string}>>
     *
     * @throws AiClientException
     */
    public function listPromptOverrideExamples(): array;

    /**
     * Trigger a blog pipeline run.
     *
     * Matches phasea's `POST api/v1/ai/content/blog-pipeline` contract:
     * `topic_id` (uuid) + `locale` are required; `config` may carry an
     * optional `pipeline_run_id` (uuid) to resume an existing run.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function triggerBlogPipeline(string $topicId, string $locale, array $config, string $operationId): array;

    /**
     * Submit feedback.
     *
     * @param  array<string, mixed>  $feedback
     *
     * @throws AiClientException
     */
    public function submitFeedback(array $feedback, string $operationId): bool;

    /**
     * Submit content for asynchronous detection and return its operation handle.
     *
     * The nullable return is preserved for compatibility. Polling is outside
     * this interface and is exposed by RemoteAiClient::pollOperation().
     *
     * @throws AiClientException
     */
    public function detect(DetectionRequest $request): ?DetectionResult;

    /**
     * Submit content for asynchronous humanization and return its operation handle.
     *
     * The nullable return is preserved for compatibility. Polling is outside
     * this interface and is exposed by RemoteAiClient::pollOperation().
     *
     * @throws AiClientException
     */
    public function humanize(HumanizationRequest $request): ?HumanizationResult;
}
