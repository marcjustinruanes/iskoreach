<?php

namespace App\Notifications;

use App\Models\Renewal;
use App\Services\RenewalWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenewalActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $renewalId, public string $event, public string $status)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['renewal_id' => $this->renewalId, 'event' => $this->event, 'status' => $this->status];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('IskoReach renewal update')->line('A scholarship renewal update or deadline reminder is available.')->action('Open your notification inbox', route('notifications.index'))->line('Sign in to review details securely.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $renewal = Renewal::find($this->renewalId);
        if (! $renewal || ! $notifiable->fresh()?->can('view', $renewal)) {
            return false;
        }

        return $this->event !== 'reminder' || ($renewal->status->value === 'pending' && $renewal->deadline_at?->isFuture() && app(RenewalWorkflow::class)->eligible($renewal->award));
    }
}
