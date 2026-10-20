<?php

namespace App\Services;

use App\Enums\RenewalStatus;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\RenewalDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Models\User;
use App\Notifications\RenewalActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RenewalWorkflow
{
    public function eligible(ScholarshipAward $award): bool
    {
        $application = $award->application;

        return $award->isCurrentlyActive() && ! $application->trashed() && ! $application->scholarship->trashed()
        && $application->scholarship->provider->verification_status->value === 'approved'
        && $application->scholarship->provider->user->role->value === 'provider';
    }

    public function create(User $user, array $data): Renewal
    {
        return DB::transaction(function () use ($user, $data): Renewal {
            $award = ScholarshipAward::findOrFail($data['award_id']);
            $this->lockAward($award);
            $award = $award->fresh();
            abort_unless($user->role->value === 'provider' && $award->application->scholarship->provider->user_id === $user->id, 403);
            abort_unless($award->application->scholarship->provider->isApproved(), 403);
            $this->check($this->eligible($award), 'This award is not currently eligible for renewal.');
            $term = AcademicTerm::query()->lockForUpdate()->findOrFail($data['academic_term_id']);
            $this->check($term->ends_on && $term->ends_on->toDateString() >= now('Asia/Manila')->toDateString(), 'Choose an academic term that has not ended.');
            $this->check(! Renewal::where('scholarship_award_id', $award->id)->where('academic_term_id', $term->id)->exists(), 'A renewal already exists for this award and term.');
            $deadline = CarbonImmutable::parse($data['deadline_at'], 'Asia/Manila')->utc();
            $this->check($deadline->isFuture(), 'Choose a future deadline.');
            $rules = $award->application->scholarship->requirements()->where('requirement_stage', 'renewal')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($rule) => $rule->only('name', 'description', 'required', 'allowed_mime_types', 'max_size_kb'))->all();
            $renewal = Renewal::create(['scholarship_award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => $deadline, 'status' => 'pending', 'requirements_snapshot' => $rules, 'history' => [], 'reminder_days' => []]);
            $this->record($user, $renewal, 'opened');
            $this->notify($award->application->student, $renewal, 'opened');

            return $renewal;
        });
    }

    private function lockAward(ScholarshipAward $award): void
    {
        $application = $award->application;
        ProviderProfile::query()->lockForUpdate()->findOrFail($application->scholarship->provider_id);
        Scholarship::withTrashed()->lockForUpdate()->findOrFail($application->scholarship_id);
        User::query()->lockForUpdate()->findOrFail($application->student_id);
        Application::withTrashed()->lockForUpdate()->findOrFail($application->id);
        ScholarshipAward::query()->lockForUpdate()->findOrFail($award->id);
    }

    public function mutate(User $user, Renewal $renewal, array $data): void
    {
        DB::transaction(function () use ($user, $renewal, $data): void {
            $this->lockAward($renewal->award);
            $item = Renewal::query()->lockForUpdate()->findOrFail($renewal->id);
            Gate::forUser($user)->authorize('view', $item);
            $this->check($item->workflow_version === $data['version'], 'This renewal changed. Reload before continuing.');
            $this->check($this->eligible($item->award), 'This award is not currently eligible for renewal changes.');
            $action = $data['action'];
            if (in_array($action, ['approved', 'rejected'], true)) {
                abort_unless($user->role->value === 'provider', 403);
                $this->check($item->status === RenewalStatus::Submitted && $item->submitted_at !== null, 'Only submitted renewals can be reviewed. Decisions are final.');
                $item->status = RenewalStatus::from($action);
                $item->reviewed_by = $user->id;
                $item->reviewed_at = now();
                $item->review_notes = trim($data['reason']);
                $this->check($item->review_notes !== '', 'Enter a decision reason.');
            } else {
                abort_unless($user->role->value === 'student', 403);
                $this->check($item->status === RenewalStatus::Pending && $item->submitted_at === null, 'Only pending renewals can change.');
                $this->check($item->deadline_at !== null && $item->deadline_at->isFuture(), 'The renewal deadline has passed.');
                $this->check($item->requirements_snapshot !== null, 'This legacy renewal has no recorded checklist and cannot be submitted.');
                if ($action === 'submit') {
                    foreach ($this->checklist($item) as $row) {
                        $this->check(! ($row['required'] || $row['attachment']) || $row['complete'], 'Complete required files and replace or remove unavailable optional files.');
                    }
                    $item->status = RenewalStatus::Submitted;
                    $item->submitted_at = now();
                    foreach ($this->checklist($item) as $row) {
                        if ($row['attachment']) {
                            RenewalDocument::whereKey($row['attachment']['id'])->update(['submitted_at' => now()]);
                        }
                    }
                } else {
                    $index = $data['requirement_index'];
                    $rules = $item->requirements_snapshot;
                    $this->check(array_key_exists($index, $rules), 'Choose a requirement from this renewal.');
                    $latest = RenewalDocument::where('renewal_id', $item->id)->where('requirement_index', $index)->latest('version')->first();
                    if ($action === 'remove') {
                        $this->check($latest !== null && $latest->removed_at === null, 'No current attachment to remove.');
                        $latest->update(['removed_at' => now()]);
                    } else {
                        $document = StudentDocument::where('student_id', $user->id)->lockForUpdate()->find($data['document_id']);
                        $this->check($document !== null && app(PrivateDocuments::class)->usable($document, new ScholarshipRequirement($rules[$index])), 'Choose your available file within this requirement’s format and size limits.');
                        RenewalDocument::create(['renewal_id' => $item->id, 'requirement_index' => $index, 'student_document_id' => $document->id, 'version' => ($latest?->version ?? 0) + 1]);
                    }
                }
            }
            $item->workflow_version++;
            $this->record($user, $item, $action);
            if (in_array($action, ['submit', 'approved', 'rejected'], true)) {
                $recipient = $action === 'submit' ? $item->award->application->scholarship->provider->user : $item->award->application->student;
                $this->notify($recipient, $item, $action);
            }
        });
    }

    public function checklist(Renewal $renewal): array
    {
        return collect($renewal->requirements_snapshot ?? [])->map(function ($rule, $index) use ($renewal): array {
            $latest = RenewalDocument::where('renewal_id', $renewal->id)->where('requirement_index', $index)->with('document')->latest('version')->first();
            $attached = $latest && ! $latest->removed_at;

            return $rule + ['index' => $index, 'complete' => $attached && app(PrivateDocuments::class)->usable($latest->document, new ScholarshipRequirement($rule)),
                'attachment' => $attached ? ['id' => $latest->id, 'file_name' => $latest->document->original_name, 'version' => $latest->version, 'download_url' => route('renewals.download', $latest)] : null];
        })->all();
    }

    public function reminders(): int
    {
        $count = 0;
        Renewal::where('status', 'pending')->whereNotNull('deadline_at')->orderBy('id')->eachById(function ($renewal) use (&$count): void {
            DB::transaction(function () use ($renewal, &$count): void {
                $this->lockAward($renewal->award);
                $item = Renewal::query()->lockForUpdate()->findOrFail($renewal->id);
                $days = (int) CarbonImmutable::now('Asia/Manila')->startOfDay()->diffInDays($item->deadline_at->toImmutable()->setTimezone('Asia/Manila')->startOfDay(), false);
                $sent = $item->reminder_days ?? [];
                if ($item->status !== RenewalStatus::Pending || ! $item->deadline_at->isFuture() || ! $this->eligible($item->award) || $item->requirements_snapshot === null || ! in_array($days, [30, 14, 7], true) || in_array($days, $sent, true)) {
                    return;
                }
                $this->notify($item->award->application->student, $item, 'reminder');
                $item->reminder_days = [...$sent, $days];
                $item->save();
                $count++;
            });
        });

        return $count;
    }

    private function record(User $user, Renewal $renewal, string $event): void
    {
        $renewal->history = [...($renewal->history ?? []), ['event' => $event, 'at' => now()->toIso8601String(), 'reason' => in_array($event, ['approved', 'rejected'], true) ? $renewal->review_notes : null]];
        $renewal->save();
        $user->activityLogs()->create(['action' => 'renewal.'.$event, 'subject_type' => Renewal::class, 'subject_id' => $renewal->id, 'changes' => [], 'occurred_at' => now()]);
    }

    private function notify(User $recipient, Renewal $renewal, string $event): void
    {
        $notice = new RenewalActivity($renewal->id, $event, $renewal->status->value);
        $recipient->notifyNow($notice, ['database']);
        $recipient->notify($notice);
        app(AccountNotifications::class)->changed($recipient);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['renewal' => $message]);
        }
    }
}
