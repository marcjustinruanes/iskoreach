<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApplicationWorkflow
{
    public function context(User $student, Scholarship $program): array
    {
        $awards = ScholarshipAward::active()->whereHas('application', fn ($query) => $query->where('student_id', $student->id))->orderBy('id')->pluck('id')->all();
        $required = $program->requirements()->where('required', true)->where('requirement_stage', 'application')->orderBy('id')->pluck('id')->all();
        $profile = $student->studentProfile;
        $identity = ['name' => $student->name, 'school' => $profile?->school, 'course' => $profile?->course, 'year_level' => $profile?->year_level];
        $open = ! $program->trashed() && $program->status->value === 'published' && $program->published_at !== null
            && $program->provider->isApproved() && $program->provider->user?->role === UserRole::Provider
            && $program->opens_at !== null && $program->opens_at->lte(now()) && $program->deadline_at !== null && $program->deadline_at->gt(now());
        $conflict = count($awards) > 0;
        $application = Application::withTrashed()->where('student_id', $student->id)->where('scholarship_id', $program->id)->first();
        $checklist = $application ? app(PrivateDocuments::class)->checklist($application) : [];
        $missing = $application ? count(array_filter($checklist, fn ($row) => ($row['required'] || $row['attachment']) && ! $row['complete'])) : count($required);

        return ['open' => $open, 'active_award_count' => count($awards), 'conflict_blocked' => $conflict && ! $program->accepts_existing_scholars,
            'low_priority' => $conflict && $program->accepts_existing_scholars, 'required_documents' => count($required), 'missing_documents' => $missing, 'profile' => $identity,
            'context_token' => hash_hmac('sha256', json_encode([$program->id, $program->management_version, $awards, $required, $identity, array_map(fn ($row) => [$row['id'], $row['attachment']['id'] ?? null, $row['complete']], $checklist)]), config('app.key'))];
    }

    public function start(User $student, Scholarship $program): Application
    {
        abort_unless($student->role === UserRole::Student, 403);

        return DB::transaction(function () use ($student, $program): Application {
            $item = $this->lockProgram($program);
            $owner = User::query()->lockForUpdate()->findOrFail($student->id);
            $existing = Application::withTrashed()->where('scholarship_id', $item->id)->where('student_id', $owner->id)->first();
            if ($existing) {
                abort_if($existing->trashed(), 409, 'A retained application already exists for this program.');

                return $existing;
            }
            if (! $this->context($owner, $item)['open']) {
                throw ValidationException::withMessages(['application' => 'This program is not accepting applications.']);
            }
            $application = Application::create(['student_id' => $owner->id, 'scholarship_id' => $item->id,
                'academic_term_id' => $item->academic_term_id, 'status' => ApplicationStatus::NotStarted]);
            $this->history($owner, $application, null, 'Draft prepared.');

            return $application->refresh();
        });
    }

    public function submit(User $student, Application $application, array $data): void
    {
        DB::transaction(function () use ($student, $application, $data): void {
            $program = $this->lockProgram($application->scholarship);
            $owner = User::query()->lockForUpdate()->findOrFail($student->id);
            $item = Application::query()->lockForUpdate()->findOrFail($application->id);
            Gate::forUser($owner)->authorize('view', $item);
            $this->version($item, $data['version']);
            if ($item->submitted_at !== null || ! in_array($item->status->value, ['not_started', 'requirements_pending'], true)) {
                throw ValidationException::withMessages(['application' => 'This application has already been submitted.']);
            }
            $context = $this->context($owner, $program);
            if (! hash_equals($context['context_token'], $data['context_token'])) {
                throw ValidationException::withMessages(['context_token' => 'Program, profile, or active-award information changed. Reload and review it before submitting.']);
            }
            if (! $context['open']) {
                throw ValidationException::withMessages(['application' => 'This program is not accepting applications.']);
            }
            if ($context['conflict_blocked']) {
                throw ValidationException::withMessages(['application' => 'This program does not accept students with an active award.']);
            }
            app(PrivateDocuments::class)->assertReady($item);
            if ($context['low_priority'] && empty($data['conflict_confirmed'])) {
                throw ValidationException::withMessages(['conflict_confirmed' => 'Confirm that an existing active award places this application in the low-priority group.']);
            }
            $from = $item->status->value;
            $item->status = ApplicationStatus::Submitted;
            $item->submitted_at = now();
            $item->academic_term_id = $program->academic_term_id;
            $item->has_existing_scholarship = $context['active_award_count'] > 0;
            $item->low_priority = $context['low_priority'];
            $item->conflict_rule_snapshot = ['accepts_existing_scholars' => $program->accepts_existing_scholars, 'active_award_count' => $context['active_award_count'], 'evaluated_at' => now()->toIso8601String()];
            $item->submission_snapshot = ['student' => $context['profile'], 'program' => ['title' => $program->title, 'version' => $program->management_version]];
            $item->workflow_version++;
            $item->save();
            app(PrivateDocuments::class)->freeze($item);
            $this->history($owner, $item, $from, 'Student confirmed submission and profile sharing.');
        });
    }

    public function review(User $provider, Application $application, array $data): void
    {
        DB::transaction(function () use ($provider, $application, $data): void {
            $program = $this->lockProgram($application->scholarship);
            User::query()->lockForUpdate()->findOrFail($application->student_id);
            $item = Application::query()->lockForUpdate()->findOrFail($application->id);
            Gate::forUser($provider)->authorize('review', $item);
            $this->version($item, $data['version']);
            if ($program->trashed()) {
                throw ValidationException::withMessages(['status' => 'The program is retained history. Further review decisions are unavailable.']);
            }
            $allowed = match ($item->status) {
                ApplicationStatus::Submitted => ['under_review', 'accepted', 'rejected', 'waitlisted'],
                ApplicationStatus::UnderReview => ['accepted', 'rejected', 'waitlisted'],
                ApplicationStatus::Waitlisted => ['under_review', 'accepted', 'rejected'],
                default => [],
            };
            if (! in_array($data['status'], $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'This transition is unavailable. Accepted and rejected decisions are final in this stage.']);
            }
            $from = $item->status->value;
            $item->status = ApplicationStatus::from($data['status']);
            $item->reviewed_by = $provider->id;
            $item->reviewed_at = now();
            $item->decision_reason = $data['reason'];
            $item->workflow_version++;
            $item->save();
            $this->history($provider, $item, $from, $data['reason']);
        });
    }

    public function details(Application $item): array
    {
        return [...$this->row($item), 'version' => $item->workflow_version,
            'academic_term' => $item->academicTerm ? $item->academicTerm->academic_year.' / '.$item->academicTerm->semester : null,
            'documents' => app(PrivateDocuments::class)->submitted($item),
            'student' => $item->submission_snapshot['student'] ?? null,
            'program_snapshot' => $item->submission_snapshot['program'] ?? null,
            'history' => ApplicationStatusHistory::where('application_id', $item->id)->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($entry): array => [
                'id' => $entry->id, 'from' => $entry->from_status, 'to' => $entry->to_status, 'reason' => $entry->reason, 'occurred_at' => $entry->occurred_at->toIso8601String()])];
    }

    public function row(Application $item): array
    {
        return ['id' => $item->id, 'scholarship_id' => $item->scholarship_id, 'title' => $item->submission_snapshot['program']['title'] ?? $item->scholarship->title,
            'provider' => $item->scholarship->provider->organization_name, 'status' => $item->status->value, 'submitted_at' => $item->submitted_at?->toIso8601String(),
            'reviewed_at' => $item->reviewed_at?->toIso8601String(), 'decision_reason' => $item->decision_reason, 'low_priority' => $item->low_priority,
            'retained' => $item->trashed(), 'program_retained' => $item->scholarship->trashed()];
    }

    private function lockProgram(Scholarship $program): Scholarship
    {
        ProviderProfile::query()->lockForUpdate()->findOrFail($program->provider_id);

        return Scholarship::withTrashed()->lockForUpdate()->findOrFail($program->id);
    }

    private function version(Application $item, int $version): void
    {
        if ($item->workflow_version !== $version) {
            throw ValidationException::withMessages(['version' => 'This application changed. Reload before continuing.']);
        }
    }

    private function history(User $actor, Application $item, ?string $from, string $reason): void
    {
        ApplicationStatusHistory::create(['application_id' => $item->id, 'from_status' => $from, 'to_status' => $item->status->value, 'changed_by' => $actor->id, 'reason' => $reason, 'occurred_at' => now()]);
        $actor->activityLogs()->create(['action' => $item->submitted_at === null ? 'application.draft_created' : ($actor->role === UserRole::Student ? 'application.submitted' : 'application.reviewed'),
            'subject_type' => Application::class, 'subject_id' => $item->id, 'changes' => ['from' => $from, 'to' => $item->status->value], 'occurred_at' => now()]);
        app(AccountNotifications::class)->application($item);
    }
}
