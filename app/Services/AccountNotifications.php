<?php

namespace App\Services;

use App\Events\AccountNotificationsChanged;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use App\Models\User;
use App\Notifications\ApplicationActivity;
use App\Notifications\AwardBenefitActivity;
use App\Notifications\ChatActivity;
use App\Notifications\ProviderVerificationDecision;
use App\Notifications\RenewalActivity;
use App\Notifications\ScholarshipNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

class AccountNotifications
{
    public function query(User $user): Builder
    {
        return $user->notifications()->whereIn('type', [ApplicationActivity::class, ProviderVerificationDecision::class, ChatActivity::class, RenewalActivity::class, AwardBenefitActivity::class, ScholarshipNotice::class, 'student_verification'])->getQuery();
    }

    public function summary(User $user): array
    {
        return ['unread' => $this->query($user)->whereNull('read_at')->count()];
    }

    public function changed(User $user): void
    {
        if (config('broadcasting.default') === 'reverb') {
            event(new AccountNotificationsChanged($user->id));
        }
    }

    public function application(Application $application): void
    {
        if ($application->submitted_at === null) {
            return;
        }
        $isSubmission = $application->status->value === 'submitted';
        $recipient = $isSubmission ? $application->scholarship->provider->user : $application->student;
        $notice = new ApplicationActivity($application->id, $isSubmission ? 'submitted' : 'reviewed', $application->status->value, $recipient->id, $recipient->role->value);
        $recipient->notifyNow($notice, ['database']);
        $recipient->notify($notice);
        $this->changed($recipient);
    }

    public function row(User $user, DatabaseNotification $notice): array
    {
        $url = null;
        if ($notice->type === 'student_verification') {
            $title = $notice->data['title'];
            $message = $notice->data['message'];
            $url = route('student.profile').'#verification';
        } elseif ($notice->type === ScholarshipNotice::class) {
            $title = $notice->data['title'];
            $message = $notice->data['message'];
            $url = route('scholarship-announcements.index');
            $application = Application::find($notice->data['application_id'] ?? 0);
            if ($application && $user->can('view', $application)) {
                $url = route('student.applications.show', $application);
            } elseif ($application && $user->can('review', $application)) {
                $url = route('provider.applications.show', $application);
            }
        } elseif ($notice->type === AwardBenefitActivity::class) {
            $title = str_starts_with($notice->data['event'] ?? '', 'release_') ? 'Benefit record update' : 'Award record update';
            $statuses = ['active' => 'Active', 'suspended' => 'Suspended', 'completed' => 'Completed', 'revoked' => 'Revoked', 'pending' => 'Pending', 'processing' => 'Processing', 'delayed' => 'Delayed', 'failed' => 'Failed', 'released' => 'Released'];
            $message = 'Recorded status: '.($statuses[$notice->data['status'] ?? ''] ?? 'Update available').'. Open the record for current details.';
            $id = $notice->data['award_id'] ?? null;
            $award = is_int($id) ? ScholarshipAward::find($id) : null;
            if ($award && $user->can('view', $award)) {
                $url = route('benefits.show', $award);
            }
        } elseif ($notice->type === RenewalActivity::class) {
            $title = ($notice->data['event'] ?? '') === 'reminder' ? 'Renewal deadline reminder' : 'Renewal update';
            $statuses = ['pending' => 'Pending', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];
            $message = 'Recorded status: '.($statuses[$notice->data['status'] ?? ''] ?? 'Update available').'. Sign in to review the renewal.';
            $id = $notice->data['renewal_id'] ?? null;
            $renewal = is_int($id) ? Renewal::find($id) : null;
            if ($renewal && $user->can('view', $renewal)) {
                $url = route('renewals.show', $renewal);
            }
        } elseif ($notice->type === ChatActivity::class) {
            $title = 'New private message';
            $message = 'Open the conversation to read your message.';
            $id = $notice->data['conversation_id'] ?? null;
            $thread = is_int($id) ? Conversation::find($id) : null;
            if ($thread && $user->can('view', $thread)) {
                $url = route('messages.show', $thread);
            }
        } elseif ($notice->type === ProviderVerificationDecision::class) {
            $title = 'Provider verification decision';
            $message = 'A provider account decision was recorded. Open your account status for details.';
            if ($user->role->value === 'provider') {
                $url = route('provider.status');
            }
        } else {
            $statuses = ['submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'waitlisted' => 'Waitlisted'];
            $title = ($notice->data['event'] ?? '') === 'submitted' ? 'New scholarship application' : 'Application status update';
            $message = 'Recorded status: '.($statuses[$notice->data['status'] ?? ''] ?? 'Update available').'.';
            $id = $notice->data['application_id'] ?? null;
            $application = is_int($id) ? Application::withTrashed()->find($id) : null;
            if ($application && $user->can('view', $application)) {
                $url = route('student.applications.show', $application);
            } elseif ($application && $user->can('review', $application)) {
                $url = route('provider.applications.show', $application);
            }
        }

        return ['id' => $notice->id, 'title' => $title, 'message' => $message, 'url' => $url, 'read_at' => $notice->read_at?->toIso8601String(), 'created_at' => $notice->created_at->toIso8601String()];
    }
}
