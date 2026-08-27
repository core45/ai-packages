<?php

declare(strict_types=1);

namespace Core45\AiChat\Console\Commands;

use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationSummary;
use Carbon\Carbon;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\ContentSummarizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `generateAISummary()` summarises a batch of customer messages via
 * `AiClientFactory::for(AiSurface::CustomerChat)->runPrompt('customer_chat.conversation_summary')`
 * (v2.6 design §2.5 — phasea owns the prompt, plan 48-11). The host sends the
 * messages as a structured list and holds no instruction text; the template
 * composes the analytics-assistant instruction and the transcript. This
 * superseded the Phase 16-05 `completeText()` wiring (SEAM-08), which in turn
 * replaced the dead Phase-10 seam placeholder. `AiClient::summarize()` is still
 * NOT used here — it delegates to `ContentSummarizerService::summarize($text,
 * $locale)`, a single-text summarizer, not this batch topics/questions/summary
 * JSON extraction prompt.
 */
class SummarizeCustomerConversations extends Command
{
    protected $signature = 'ai:summarize-conversations
                            {--date= : Date to summarize (YYYY-MM-DD, defaults to yesterday)}
                            {--period=daily : Period type (daily, weekly, monthly)}
                            {--force : Force regeneration even if summary exists}';

    protected $description = 'Generate AI-powered summaries of customer conversations';

    public function handle(AiClientFactory $factory): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $period = $this->option('period');
        $force = $this->option('force');

        $this->info("Summarizing {$period} customer conversations for {$date->toDateString()}...");

        // Get date range based on period
        [$startDate, $endDate] = $this->getDateRange($date, $period);

        // Check if summary already exists
        $existingSummary = AIConversationSummary::query()
            ->where('summary_date', $date->toDateString())
            ->where('period_type', $period)
            ->whereNull('locale')
            ->first();

        if ($existingSummary && !$force) {
            $this->warn('Summary already exists for this date. Use --force to regenerate.');

            return self::SUCCESS;
        }

        // Get customer conversations for the period
        $conversations = AIConversation::query()
            ->customer()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('messages')
            ->get();

        if ($conversations->isEmpty()) {
            $this->info('No customer conversations found for this period.');

            return self::SUCCESS;
        }

        // Collect all user messages
        $userMessages = $conversations->flatMap(function ($conv) {
            return $conv->messages->where('role', 'user')->pluck('content');
        })->filter()->values();

        $this->info("Found {$conversations->count()} conversations with {$userMessages->count()} user messages.");

        // Generate AI summary
        $summaryData = $this->generateAISummary($userMessages->toArray(), $factory);

        // Save summary
        AIConversationSummary::updateOrCreate(
            [
                'summary_date' => $date->toDateString(),
                'period_type' => $period,
                'locale' => null,
            ],
            [
                'conversation_count' => $conversations->count(),
                'message_count' => $conversations->sum(fn ($c) => $c->messages->count()),
                'user_message_count' => $userMessages->count(),
                'top_topics' => $summaryData['topics'] ?? [],
                'common_questions' => $summaryData['questions'] ?? [],
                'ai_summary' => $summaryData['summary'] ?? null,
                // v2.6: no 'model_used'. This persisted a model name read from host
                // config, but the host does not choose the model — phasea routes the
                // summarize task — so the stored value was a guess that silently
                // disagreed with what actually ran.
                'metadata' => [
                    'generated_at' => now()->toIso8601String(),
                ],
            ]
        );

        $this->info('Summary saved successfully!');

        if (!empty($summaryData['topics'])) {
            $this->newLine();
            $this->info('Top topics:');
            foreach (array_slice($summaryData['topics'], 0, 5) as $topic) {
                $this->line("  - {$topic}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getDateRange(Carbon $date, string $period): array
    {
        return match ($period) {
            'weekly' => [
                $date->copy()->startOfWeek(),
                $date->copy()->endOfWeek(),
            ],
            'monthly' => [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth(),
            ],
            default => [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ],
        };
    }

    /**
     * @param  array<string>  $messages
     * @return array{topics: array<string>, questions: array<string>, summary: string}
     */
    private function generateAISummary(array $messages, AiClientFactory $factory): array
    {
        if (empty($messages)) {
            return ['topics' => [], 'questions' => [], 'summary' => ''];
        }

        $operationId = (string) Str::uuid();

        $this->line("  [operation_id: {$operationId}]");

        // Sample to the template's 100-message window (avoids token limits); the
        // `customer_chat.conversation_summary` template owns the transcript
        // formatting and the analytics-assistant instruction (v2.6 design §2.5).
        $messageSample = array_slice($messages, 0, 100);

        try {
            $client = $factory->for(AiSurface::CustomerChat);
            $response = $client->runPrompt(
                'customer_chat.conversation_summary',
                ['messages' => array_values($messageSample)],
                $operationId,
            );

            $decoded = json_decode($response->text, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return [
                    'topics' => $decoded['topics'] ?? [],
                    'questions' => $decoded['questions'] ?? [],
                    'summary' => $decoded['summary'] ?? '',
                ];
            }

            // If JSON parsing failed, return raw text as summary
            return [
                'topics' => [],
                'questions' => [],
                'summary' => $response->text,
            ];
        } catch (\Exception $e) {
            $this->error('AI summarization failed: '.$e->getMessage());

            return [
                'topics' => [],
                'questions' => [],
                'summary' => 'Failed to generate AI summary: '.$e->getMessage(),
            ];
        }
    }
}
