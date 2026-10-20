<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Models\Application;
use App\Models\Renewal;
use App\Models\StudentProfile;
use App\Services\RenewalWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'profile' => $profile->only('id', 'school', 'course', 'year_level'),
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
            'profile' => $profile->only('id', 'school', 'course', 'year_level'),
            'accountCreatedAt' => $request->user()->created_at?->toIso8601String(),
        ]);
    }

    public function update(UpdateStudentProfileRequest $request, StudentProfile $studentProfile): RedirectResponse
    {
        $studentProfile->update($request->validated());

        return to_route('student.profile')->with('status', 'Your profile has been saved.');
    }
}
