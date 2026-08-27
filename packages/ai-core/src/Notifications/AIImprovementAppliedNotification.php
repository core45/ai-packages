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
use Illuminate\Support\Facades\Route;

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
        $message = (new MailMessage)
            ->subject(__('AI Improvement Applied'))
            ->greeting(__('Improvement Successfully Applied'))
            ->line("**{$this->suggestion->title}**")
            ->line(__('Type').': '.$this->suggestion->type->label())
            ->line('')
            ->line(__('The following improvement has been automatically applied to the AI system').':')
            ->line($this->truncate($this->suggestion->problem_summary, 300));

        $viewUrl = $this->viewUrl();

        if ($viewUrl !== null) {
            $message->action(__('View Details'), $viewUrl);
        }

        return $message->line(__('The change is now active and will affect future AI interactions.'));
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
     * Send a Filament database notification where Filament is installed, and a
     * plain payload where it is not.
     *
     * This is the one notification in the packages that genuinely wants a rich
     * payload — an icon and a "View" button — which the `NotifiesOperator` seam
     * deliberately does not carry. Rather than split this class across two
     * packages for the sake of one method, the Filament payload is built only
     * when Filament is actually present.
     *
     * The `use Filament\...` imports above cost nothing here: a use statement
     * is a compile-time alias and triggers no autoload, so this class stays
     * loadable in an app that has no Filament at all.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        // Both classes are checked, not just the notification one: they ship in
        // DIFFERENT composer packages (filament/notifications and
        // filament/actions). `filament/filament` pulls both, so an app on a
        // Filament panel always has the pair — but nothing stops an app from
        // requiring filament/notifications alone, and there the guard would
        // pass and `Action::make()` below would then fatal.
        if (!class_exists(FilamentNotification::class) || !class_exists(Action::class)) {
            return $this->toArray($notifiable);
        }

        $notification = FilamentNotification::make()
            ->success()
            ->title(__('AI Improvement Applied'))
            ->body($this->suggestion->title)
            ->icon('heroicon-o-check-badge')
            ->iconColor('success');

        $viewUrl = $this->viewUrl();

        if ($viewUrl !== null) {
            $notification->actions([
                Action::make('view')
                    ->label(__('View'))
                    ->url($viewUrl),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    /**
     * URL of the feedback analytics page, or null when it is not routable.
     *
     * That page ships with `ai-core-filament`. An app on a different panel — or
     * on none — has no such route, and `route()` throws for a name that does
     * not exist, which would fail the queued notification rather than degrade
     * it to one without a button.
     */
    private function viewUrl(): ?string
    {
        if (!Route::has('filament.admin.pages.ai-feedback-analytics')) {
            return null;
        }

        return route('filament.admin.pages.ai-feedback-analytics', ['tab' => 'queue']);
    }

    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length).'...';
    }
}
