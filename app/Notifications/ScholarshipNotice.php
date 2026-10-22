<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ScholarshipNotice extends Notification
{
    public function __construct(public int $scholarshipId, public string $title, public string $message, public ?int $applicationId = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['scholarship_id' => $this->scholarshipId, 'application_id' => $this->applicationId, 'title' => $this->title, 'message' => $this->message];
    }
}
