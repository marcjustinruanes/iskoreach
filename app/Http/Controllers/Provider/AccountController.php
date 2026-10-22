<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\UpdateProviderProfileRequest;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Notifications\ProviderVerificationDecision;
use App\Support\InputRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $profile = $request->user()->providerProfile;
        abort_unless($profile, 403, 'Run the account profile migration before using this page.');
        Gate::authorize('view', $profile);

        if (! $profile->isApproved()) {
            return to_route('provider.status');
        }

        return Inertia::render('provider/Dashboard', [
            'profile' => $profile->only('id', 'organization_name', 'contact_email', 'verification_status', 'rejection_reason'),
            'verificationDocuments' => collect($profile->verification_documents ?? [])->map(fn (array $document, string $kind): array => ['kind' => $kind, 'url' => route('provider-verification.show', [$profile, $kind])])->values(),
            'verificationUpload' => $profile->registration_version > 0 ? ['url' => route('provider-verification.update', $profile), 'version' => $profile->verification_version, 'individual' => $profile->organization_type === 'individual', 'authorization_required' => $profile->authorization_required] : null,
            'verificationNotices' => $this->verificationNotices($request),
            'summary' => [
                'scholarships' => $profile->scholarships()->count(),
                'published' => $profile->scholarships()->where('status', 'published')->count(),
                'draft' => $profile->scholarships()->where('status', 'draft')->count(),
                'closed' => $profile->scholarships()->where('status', 'closed')->count(),
                'retained' => $profile->scholarships()->onlyTrashed()->count(),
                'submitted' => $this->submittedApplications($profile)->count(),
            ],
            'recentScholarships' => $profile->scholarships()->withCount(['applications as submitted_count' => fn ($query) => $query->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())->whereHas('student', fn ($student) => $student->where('role', UserRole::Student))])->latest('updated_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn ($scholarship): array => ['id' => $scholarship->id, 'title' => $scholarship->title, 'status' => $scholarship->status->value,
                    'submitted_count' => $scholarship->submitted_count, 'deadline_at' => $scholarship->deadline_at?->toIso8601String(), 'updated_at' => $scholarship->updated_at->toIso8601String(), 'counts_url' => route('provider.applicant-counts.show', $scholarship)]),
            'reviewSummary' => $this->submittedApplications($profile)->whereNull('deleted_at')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count),
            'recentSubmissions' => $this->submittedApplications($profile)->whereNull('deleted_at')->with(['student:id,name', 'scholarship:id,title'])->latest('submitted_at')->orderByDesc('id')->limit(6)->get()
                ->map(fn ($item): array => ['id' => $item->id, 'student' => $item->student->name, 'scholarship' => $item->scholarship->title,
                    'status' => $item->status->value, 'submitted_at' => $item->submitted_at->toIso8601String(), 'url' => route('provider.applications.show', $item)]),
            'applicationActivity' => $this->submittedApplications($profile)->with('scholarship:id,title,deleted_at')
                ->selectRaw('scholarship_id, status, COUNT(*) AS total, MAX(submitted_at) AS last_submitted_at')
                ->groupBy('scholarship_id', 'status')->orderByDesc('last_submitted_at')->orderBy('scholarship_id')->orderBy('status')->limit(5)->get()
                ->map(fn ($application): array => ['scholarship_id' => $application->scholarship_id, 'scholarship' => $application->scholarship->title, 'status' => $application->status->value,
                    'total' => (int) $application->total, 'last_submitted_at' => CarbonImmutable::parse($application->last_submitted_at)->toIso8601String()]),
        ]);
    }

    public function profile(Request $request): Response
    {
        $profile = $request->user()->providerProfile;
        abort_unless($profile, 403);
        Gate::authorize('view', $profile);

        return Inertia::render('provider/Profile', [
            'profile' => $profile->only('id', 'organization_name', 'contact_email', 'verification_status', 'rejection_reason'),
            'verificationDocuments' => collect($profile->verification_documents ?? [])->map(fn (array $document, string $kind): array => ['kind' => $kind, 'url' => route('provider-verification.show', [$profile, $kind])])->values(),
            'verificationUpload' => $profile->registration_version > 0 ? ['url' => route('provider-verification.update', $profile), 'version' => $profile->verification_version, 'individual' => $profile->organization_type === 'individual', 'authorization_required' => $profile->authorization_required] : null,
            'verificationNotices' => $this->verificationNotices($request),
        ]);
    }

    public function status(Request $request): Response|RedirectResponse
    {
        $profile = $request->user()->providerProfile;
        abort_unless($profile, 403, 'Run the account profile migration before using this page.');
        Gate::authorize('view', $profile);

        if ($profile->isApproved()) {
            return to_route('provider.dashboard');
        }

        return Inertia::render('provider/Status', [
            'profile' => $profile->only('id', 'organization_name', 'contact_email', 'verification_status', 'rejection_reason'),
            'verificationDocuments' => collect($profile->verification_documents ?? [])->map(fn (array $document, string $kind): array => ['kind' => $kind, 'url' => route('provider-verification.show', [$profile, $kind])])->values(),
            'verificationUpload' => $profile->registration_version > 0 ? ['url' => route('provider-verification.update', $profile), 'version' => $profile->verification_version, 'individual' => $profile->organization_type === 'individual', 'authorization_required' => $profile->authorization_required] : null,
            'verificationNotices' => $this->verificationNotices($request),
        ]);
    }

    public function update(UpdateProviderProfileRequest $request, ProviderProfile $providerProfile): RedirectResponse
    {
        $changed = DB::transaction(function () use ($request, $providerProfile): bool {
            $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($providerProfile->id);
            $data = $request->validated();
            $original = InputRules::normalize(['contact_email' => $profile->contact_email], ['contact_email'], ['contact_email']);
            if ($data['contact_email'] === $original['contact_email']) {
                $data['contact_email'] = $profile->contact_email;
            }
            $profile->fill($data);

            if ($profile->isDirty(['organization_name', 'contact_email'])) {
                $before = $profile->verification_status;
                $profile->verification_status = ProviderVerificationStatus::Pending;
                $profile->reviewed_by = null;
                $profile->reviewed_at = null;
                $profile->rejection_reason = null;
                $profile->verification_version++;
                $profile->save();

                $request->user()->activityLogs()->create([
                    'action' => 'provider.details_changed',
                    'subject_type' => ProviderProfile::class,
                    'subject_id' => $profile->id,
                    'changes' => ['from' => $before->value, 'to' => 'pending'],
                    'occurred_at' => now(),
                ]);

                return true;
            }

            return false;
        });

        return to_route('provider.dashboard')->with('status', $changed
            ? 'Organization details saved. Your account is pending administrator review.'
            : 'No changes were made. Your verification status is unchanged.');
    }

    public function workspace(Request $request): Response
    {
        Gate::authorize('accessWorkspace', $request->user()->providerProfile);

        return Inertia::render('provider/Workspace');
    }

    private function verificationNotices(Request $request): array
    {
        return $request->user()->notifications()->where('type', ProviderVerificationDecision::class)
            ->latest('created_at')->limit(5)->get()->map(fn ($notice): array => [
                'id' => $notice->id,
                'status' => $notice->data['status'],
                'reason' => $notice->data['reason'] ?? null,
                'decided_at' => $notice->data['decided_at'],
            ])->all();
    }

    private function submittedApplications(ProviderProfile $profile): Builder
    {
        return Application::withTrashed()->whereIn('status', ApplicationStatus::countedValues())->whereNotNull('submitted_at')
            ->whereHas('student', fn ($query) => $query->where('role', UserRole::Student))
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $profile->id));
    }
}
