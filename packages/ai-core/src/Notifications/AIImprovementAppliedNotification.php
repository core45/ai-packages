<?php

declare(strict_types=1);

namespace Core45\AiCore\Notifications;

use Core45\AiCore\Models\AIImprovementSuggestion;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AIImprovementAppliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AIImprovementSuggestion $suggestion
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('ai.assistant.feedback_analysis.notifications.email_enabled', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $viewUrl = route('filament.admin.pages.ai-feedback-analytics', ['tab' => 'queue']);

        return (new MailMessage)
            ->subject(__('AI Improvement Applied'))
            ->greeting(__('Improvement Successfully Applied'))
            ->line("**{$this->suggestion->title}**")
            ->line(__('Type').': '.$this->suggestion->type->label())
            ->line('')
            ->line(__('The following improvement has been automatically applied to the AI system').':')
            ->line($this->truncate($this->suggestion->problem_summary, 300))
            ->action(__('View Details'), $viewUrl)
            ->line(__('The change is now active and will affect future AI interactions.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'suggestion_id' => $this->suggestion->id,
            'title' => $this->suggestion->title,
            'type' => $this->suggestion->type->value,
            'applied_at' => $this->suggestion->applied_at?->toIso8601String(),
        ];
    }

    /**
     * Send Filament database notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->success()
            ->title(__('AI Improvement Applied'))
            ->body($this->suggestion->title)
            ->icon('heroicon-o-check-badge')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label(__('View'))
                    ->url(route('filament.admin.pages.ai-feedback-analytics', ['tab' => 'queue'])),
            ])
            ->getDatabaseMessage();
    }

    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length).'...';
    }
}
