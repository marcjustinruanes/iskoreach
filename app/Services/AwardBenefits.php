<?php

namespace App\Services;

use App\Enums\AwardStatus;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use App\Notifications\AwardBenefitActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AwardBenefits
{
    public function context(Application $application): array
    {
        $awards = ScholarshipAward::whereHas('application', fn ($query) => $query->where('student_id', $application->student_id))->orderBy('id')->get(['id', 'status', 'starts_on', 'ends_on', 'revoked_at', 'workflow_version']);

        $declarations = app(ScholarshipNotices::class)->declarations($application->student, $application->scholarship);

        return ['declared_count' => count($declarations), 'count' => $awards->where('status', AwardStatus::Active)->count(), 'token' => hash_hmac('sha256', json_encode([$application->id, $application->workflow_version, $application->scholarship->accepts_existing_scholars, $awards->toArray(), $declarations]), config('app.key'))];
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }

    private function lockApplication(User $user, Application $application): Application
    {
        $program = $application->scholarship;
        $provider = ProviderProfile::query()->lockForUpdate()->findOrFail($program->provider_id);
        abort_unless($user->role->value === 'provider' && $provider->user_id === $user->id && $provider->isApproved(), 403);
        $program = Scholarship::withTrashed()->lockForUpdate()->findOrFail($program->id);
        User::query()->lockForUpdate()->findOrFail($application->student_id);
        $item = Application::withTrashed()->lockForUpdate()->findOrFail($application->id);
        $this->check(! $item->trashed() && ! $program->trashed() && $item->status->value === 'accepted' && $item->submitted_at !== null && $item->student->role->value === 'student', 'This operation requires a current accepted student application and current scholarship.');

        return $item;
    }

    public function create(User $user, Application $application, array $data): ScholarshipAward
    {
        return DB::transaction(function () use ($user, $application, $data): ScholarshipAward {
            $item = $this->lockApplication($user, $application);
            $this->check($item->workflow_version === $data['version'], 'The application changed. Reload before confirming an award.');
            $this->check(hash_equals($this->context($item)['token'], $data['context_token']), 'The student award context changed. Reload before confirming.');
            $this->check(! ScholarshipAward::where('application_id', $item->id)->exists(), 'This application already has an award. One award is retained per application.');
            $slots = $item->scholarship->eligibility_rules['provider_settings']['slots'] ?? null;
            $allocated = ScholarshipAward::whereHas('application', fn ($query) => $query->where('scholarship_id', $item->scholarship_id))->whereIn('status', ['active', 'suspended'])->count();
            $this->check($slots === null || $allocated < $slots, 'All configured scholarship award slots are allocated.');
            $declarations = app(ScholarshipNotices::class)->declarations($item->student, $item->scholarship, $data['starts_on'], $data['ends_on'] ?? null);
            $this->check(! $item->scholarship->require_verified_declarations || collect($declarations)->every(fn (array $row): bool => $row['verification'] === 'verified'), 'Declared scholarships require verification by this provider.');
            $overlap = count($declarations) > 0 || ScholarshipAward::where('status', 'active')->whereNull('revoked_at')->whereHas('application', fn ($query) => $query->where('student_id', $item->student_id)->where('status', 'accepted')->whereNotNull('submitted_at'))
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['starts_on']))
                ->when($data['ends_on'] ?? null, fn ($query, $end) => $query->whereDate('starts_on', '<=', $end))->exists();
            $this->check(! $overlap || $item->scholarship->accepts_existing_scholars, 'This program disallows an overlapping award for an existing scholar.');
            $this->check(! $overlap || ! empty($data['conflict_confirmed']), 'Confirm the student already has an overlapping award.');
            AcademicTerm::query()->lockForUpdate()->findOrFail($data['academic_term_id']);
            $award = new ScholarshipAward(['application_id' => $item->id, 'academic_term_id' => $data['academic_term_id'], 'status' => 'active', 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null]);
            $award->history = [];
            $award->save();
            $this->record($user, $award, 'award.created', $data['reason']);
            $this->notify($award, 'award_created');

            return $award;
        });
    }

    public function transition(User $user, ScholarshipAward $award, array $data): void
    {
        DB::transaction(function () use ($user, $award, $data): void {
            $this->lockApplication($user, $award->application);
            $item = ScholarshipAward::query()->lockForUpdate()->findOrFail($award->id);
            Gate::forUser($user)->authorize('manage', $item);
            $this->check($item->workflow_version === $data['version'], 'The award changed. Reload before continuing.');
            $this->check(in_array($item->status->value, ['active', 'suspended'], true) && $item->revoked_at === null, 'Only active or suspended award records can change. Completed and revoked awards are final.');
            $this->check(in_array($data['action'], ['award_complete', 'award_revoke', 'award_suspend', 'award_resume'], true), 'Choose a valid award action.');
            if ($data['action'] === 'award_suspend') {
                $this->check($item->status->value === 'active', 'Only an active award can be suspended.');
            }
            if ($data['action'] === 'award_resume') {
                $this->check($item->status->value === 'suspended', 'Only a suspended award can resume.');
                $context = app(ApplicationWorkflow::class)->context($item->application->student, $item->application->scholarship);
                $this->check(! $context['conflict_blocked'] && ! $context['verification_blocked'], 'Resolve scholarship compatibility and declaration verification before resuming.');
                $this->check($item->ends_on === null || $item->ends_on->toDateString() >= now('Asia/Manila')->toDateString(), 'An expired award cannot resume without an approved coverage period.');
            }
            $item->status = match ($data['action']) {
                'award_complete' => 'completed', 'award_suspend' => 'suspended', 'award_resume' => 'active', default => 'revoked'
            };
            $item->revoked_at = $data['action'] === 'award_revoke' ? now() : null;
            $item->public_name = null;
            $item->published_at = null;
            $item->published_by = null;
            $item->publication_version++;
            $item->workflow_version++;
            $this->record($user, $item, match ($data['action']) {
                'award_complete' => 'award.completed', 'award_suspend' => 'award.suspended', 'award_resume' => 'award.resumed', default => 'award.revoked'
            }, $data['reason']);
            $this->notify($item, 'award_updated');
        });
    }

    public function createRelease(User $user, ScholarshipAward $award, array $data): BenefitRelease
    {
        return DB::transaction(function () use ($user, $award, $data): BenefitRelease {
            $this->lockApplication($user, $award->application);
            $item = ScholarshipAward::query()->lockForUpdate()->findOrFail($award->id);
            Gate::forUser($user)->authorize('manage', $item);
            $scheduled = empty($data['scheduled_at']) ? null : CarbonImmutable::parse($data['scheduled_at'], 'Asia/Manila')->utc();
            $fingerprint = hash('sha256', json_encode([(int) $data['academic_term_id'], isset($data['amount']) ? number_format((float) $data['amount'], 2, '.', '') : null, $data['currency'], $scheduled?->toIso8601String(), trim($data['reason'])]));
            $existing = BenefitRelease::where('scholarship_award_id', $item->id)->where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                $this->check(hash_equals($existing->creation_hash ?? '', $fingerprint), 'This retry token was used for different release details.');

                return $existing;
            }
            $this->check($item->workflow_version === $data['version'], 'The award changed. Reload before recording a release.');
            $this->check($item->isCurrentlyActive(), 'Only a currently active award can have new benefit records.');
            $allowed = (int) $data['academic_term_id'] === $item->academic_term_id || Renewal::where('scholarship_award_id', $item->id)->where('academic_term_id', $data['academic_term_id'])->where('status', 'approved')->whereNotNull('reviewed_at')->exists();
            $this->check($allowed, 'Choose the award term or a term with an approved renewal.');
            $release = new BenefitRelease(['scholarship_award_id' => $item->id, 'academic_term_id' => $data['academic_term_id'], 'amount' => $data['amount'] ?? null, 'currency' => $data['currency'], 'scheduled_at' => $scheduled, 'status' => 'pending', 'recorded_by' => $user->id, 'notes' => trim($data['reason'])]);
            $release->submission_token = $data['submission_token'];
            $release->creation_hash = $fingerprint;
            $release->history = [];
            $release->save();
            $this->record($user, $release, 'release.created', $data['reason']);
            $this->notify($item, 'release_created', $release);

            return $release;
        });
    }

    public function releaseTransition(User $user, BenefitRelease $release, array $data): void
    {
        DB::transaction(function () use ($user, $release, $data): void {
            $this->lockApplication($user, $release->award->application);
            $award = ScholarshipAward::query()->lockForUpdate()->findOrFail($release->scholarship_award_id);
            Gate::forUser($user)->authorize('manage', $award);
            $item = BenefitRelease::query()->lockForUpdate()->findOrFail($release->id);
            $this->check($item->workflow_version === $data['version'], 'The release record changed. Reload before continuing.');
            $this->check($award->isCurrentlyActive(), 'Benefit updates require a currently active award.');
            $this->check($item->status->value !== 'released', 'Released records are final and cannot be changed.');
            $this->check(in_array($data['action'], ['release_release', 'release_delay', 'release_fail'], true) || ($data['action'] === 'release_process' && in_array($item->status->value, ['pending', 'delayed', 'failed'], true)), 'Choose a valid release transition.');
            if ($data['action'] === 'release_release') {
                $date = CarbonImmutable::parse($data['released_at'], 'Asia/Manila')->utc();
                $this->check($date->lte(now()), 'An actual release timestamp cannot be in the future.');
                $this->check($date->setTimezone('Asia/Manila')->toDateString() >= $award->starts_on->toDateString(), 'The release cannot precede the award start date.');
                $item->released_at = $date;
                $item->status = 'released';
            } else {
                $item->status = match ($data['action']) {
                    'release_delay' => 'delayed', 'release_fail' => 'failed', default => 'processing'
                };
            }
            $item->recorded_by = $user->id;
            $item->workflow_version++;
            $this->record($user, $item, 'release.'.$item->status->value, $data['reason']);
            $this->notify($award, 'release_updated', $item);
        });
    }

    private function record(User $user, ScholarshipAward|BenefitRelease $record, string $event, string $reason): void
    {
        $reason = trim($reason);
        $this->check(mb_strlen($reason) >= 5, 'Enter an explanation of at least five characters.');
        $record->history = [...($record->history ?? []), ['event' => $event, 'at' => now()->toIso8601String(), 'reason' => $reason]];
        $record->save();
        $user->activityLogs()->create(['action' => $event, 'subject_type' => $record::class, 'subject_id' => $record->id, 'changes' => [], 'occurred_at' => now()]);
    }

    private function notify(ScholarshipAward $award, string $event, ?BenefitRelease $release = null): void
    {
        $student = $award->application->student;
        $notice = new AwardBenefitActivity($award->id, $release?->id, $event, $release?->status->value ?? $award->status->value);
        $student->notifyNow($notice, ['database']);
        $student->notify($notice);
        app(AccountNotifications::class)->changed($student);
    }
}
