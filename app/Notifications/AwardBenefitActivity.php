<?php

namespace App\Notifications;

use App\Models\ScholarshipAward;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AwardBenefitActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $awardId, public ?int $releaseId, public string $event, public string $status)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['award_id' => $this->awardId, 'release_id' => $this->releaseId, 'event' => $this->event, 'status' => $this->status];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('IskoReach award or benefit update')->line('An award or benefit record update is available.')->action('Open your notification inbox', route('notifications.index'))->line('Sign in to review the recorded details securely.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $award = ScholarshipAward::find($this->awardId);

        return $award !== null && (bool) $notifiable->fresh()?->can('view', $award) && $notifiable->fresh()?->role->value === 'student';
    }
}
