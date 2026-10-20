<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProviderVerificationDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $providerProfileId,
        public string $status,
        public ?string $reason,
        public string $decidedAt,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('IskoReach provider account review')
            ->line('Your provider account was '.($this->status === 'approved' ? 'approved.' : 'not approved.'))
            ->line('Decision recorded: '.$this->decidedAt.' (UTC).');
        if ($this->reason !== null) {
            $message->line('Reason: '.$this->reason);
        }

        return $message->action('View your current account status', route('provider.status'))
            ->line('Your current account status is always available after signing in.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'provider_profile_id' => $this->providerProfileId,
            'status' => $this->status,
            'reason' => $this->reason,
            'decided_at' => $this->decidedAt,
        ];
    }
}
