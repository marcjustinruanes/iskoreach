<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $applicationId, public string $event, public string $status, public int $recipientId, public string $recipientRole)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['application_id' => $this->applicationId, 'event' => $this->event, 'status' => $this->status];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('IskoReach application activity')->line('There is an update for one of your scholarship applications.')
            ->action('Open your notification inbox', route('notifications.index'))
            ->line('Sign in to review the recorded update. Private profile details, documents, and decision explanations are available only in your authorized workspace.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($notifiable->id !== $this->recipientId || $notifiable->role->value !== $this->recipientRole) {
            return false;
        }
        $application = Application::withTrashed()->find($this->applicationId);

        return $application !== null && ($this->recipientRole === 'student'
            ? $notifiable->can('view', $application) : $notifiable->can('review', $application));
    }
}
