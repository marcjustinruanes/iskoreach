<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Models\Application;
use App\Models\Renewal;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\PrivateDocuments;
use App\Services\RenewalWorkflow;
use App\Services\StudentAccountSetup;
use App\Services\StudentRegistration;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request, RenewalWorkflow $renewals): Response
    {
        $profile = $request->user()->studentProfile;
        abort_unless($profile, 403, 'Run the account profile migration before using this page.');
        Gate::authorize('view', $profile);

        $applications = Application::withTrashed()->where('student_id', $request->user()->id)
            ->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues());
        $counts = (clone $applications)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('student/Dashboard', [
            'accountSetup' => app(StudentAccountSetup::class)->data($request->user()),
            'profile' => $profile->only(array_merge(['id'], StudentRegistration::FIELDS, ['region_name', 'province_name', 'city_name', 'barangay_name'])),
            'nextActions' => Application::where('student_id', $request->user()->id)->whereIn('status', ['not_started', 'requirements_pending', 'submitted', 'under_review', 'waitlisted'])->with('scholarship.requirements')->latest('updated_at')->limit(12)->get()->map(function (Application $item): array {
                $checklist = app(PrivateDocuments::class)->checklist($item);

                return ['title' => $item->scholarship->title, 'missing' => collect($checklist)->where('required', true)->where('complete', false)->count(), 'rejected' => collect($checklist)->filter(fn ($row): bool => ($row['attachment']['review_status'] ?? null) === 'rejected')->count(), 'deadline' => $item->scholarship->deadline_at?->toIso8601String(), 'url' => route('student.applications.show', $item)];
            })->filter(fn ($row): bool => $row['missing'] > 0 || $row['rejected'] > 0 || ($row['deadline'] && Carbon::parse($row['deadline'])->between(now(), now()->addDays(14))))->take(5)->values(),
            'applicationSummary' => ['total' => (int) $counts->sum(), ...collect(ApplicationStatus::countedValues())
                ->mapWithKeys(fn (string $status): array => [$status => (int) ($counts[$status] ?? 0)])->all()],
            'recentApplications' => (clone $applications)->with('scholarship.provider')->latest('submitted_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Application $item): array => ['id' => $item->id,
                    'title' => $item->submission_snapshot['program']['title'] ?? $item->scholarship->title,
                    'provider' => $item->scholarship->provider->organization_name, 'status' => $item->status->value,
                    'submitted_at' => $item->submitted_at->toIso8601String(), 'retained' => $item->trashed(),
                    'url' => route('student.applications.show', $item)]),
            'upcomingRenewals' => Renewal::whereHas('award', fn ($query) => $query->active()
                ->whereHas('application', fn ($application) => $application->where('student_id', $request->user()->id)->whereNull('deleted_at')
                    ->whereHas('scholarship', fn ($program) => $program->whereNull('deleted_at')
                        ->whereHas('provider', fn ($provider) => $provider->where('verification_status', 'approved')->whereHas('user', fn ($user) => $user->where('role', 'provider'))))))
                ->where('status', 'pending')->whereNull('submitted_at')->whereNotNull('requirements_snapshot')->where('deadline_at', '>', now())
                ->with('award.application.student', 'award.application.scholarship.provider.user')->orderBy('deadline_at')->orderBy('id')->limit(5)->get()
                ->filter(fn (Renewal $item): bool => $renewals->eligible($item->award))->take(5)->values()
                ->map(fn (Renewal $item): array => ['id' => $item->id, 'title' => $item->award->application->scholarship->title,
                    'deadline_at' => $item->deadline_at->toIso8601String(), 'url' => route('renewals.show', $item)]),
        ]);
    }

    public function profile(Request $request): Response
    {
        $profile = $request->user()->studentProfile;
        abort_unless($profile, 403, 'Your student profile is unavailable.');
        Gate::authorize('view', $profile);

        return Inertia::render('student/Profile', [
            'accountSetup' => app(StudentAccountSetup::class)->data($request->user()),
            'profile' => $profile->only(array_merge(['id'], StudentRegistration::FIELDS, ['region_name', 'province_name', 'city_name', 'barangay_name'])),
            'accountCreatedAt' => $request->user()->created_at?->toIso8601String(),
        ]);
    }

    public function update(UpdateStudentProfileRequest $request, StudentProfile $studentProfile): RedirectResponse
    {
        DB::transaction(function () use ($request, $studentProfile): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $data = $request->validated();
            if (array_intersect(array_keys($data), StudentAccountSetup::ADDRESS)) {
                $data += app(StudentRegistration::class)->address(array_replace($studentProfile->toArray(), $data));
            }
            $studentProfile->fill($data);
            if ($studentProfile->isDirty()) {
                app(StudentAccountSetup::class)->invalidate($user);
            }
            $studentProfile->save();
            if ($studentProfile->first_name && $studentProfile->surname) {
                $user->name = app(StudentRegistration::class)->name($studentProfile->toArray());
                $user->save();
            }
        });

        return to_route('student.profile')->with('status', 'Your profile has been saved.');
    }
}
