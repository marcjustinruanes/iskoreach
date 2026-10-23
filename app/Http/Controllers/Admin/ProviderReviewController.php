<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProvidersRequest;
use App\Http\Requests\Admin\ReviewProviderRequest;
use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Notifications\ProviderVerificationDecision;
use App\Services\AccountNotifications;
use App\Services\ProviderRegistration;
use App\Services\ScholarshipAcademicPriority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderReviewController extends Controller
{
    public function index(ListProvidersRequest $request): Response
    {
        Gate::authorize('viewAny', ProviderProfile::class);
        $search = trim($request->validated('q') ?? '');
        $status = $request->validated('status') ?? '';

        return Inertia::render('admin/Providers', [
            'filters' => ['q' => $search, 'status' => $status],
            'providers' => ProviderProfile::query()
                ->whereHas('user', fn ($query) => $query->where('role', UserRole::Provider->value))
                ->when($status !== '', fn ($query) => $query->where('verification_status', $status))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('organization_name', '%'.$search.'%')
                    ->orWhereLike('contact_email', '%'.$search.'%')
                    ->orWhereHas('user', fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%'))))
                ->with('user:id,name,email')
                ->orderByRaw("CASE WHEN verification_status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(10)->withQueryString()
                ->through(fn (ProviderProfile $profile): array => $this->providerData($profile)),
        ]);
    }

    public function show(ProviderProfile $providerProfile): Response
    {
        Gate::authorize('view', $providerProfile);
        abort_unless($providerProfile->user->role === UserRole::Provider, 404);
        $providerProfile->load('reviewer:id,name');

        return Inertia::render('admin/ProviderDetails', [
            'provider' => [
                ...$this->providerData($providerProfile),
                'registration_details' => $providerProfile->only(ProviderRegistration::FIELDS) + ['scholarship_eligibility' => app(ScholarshipAcademicPriority::class)->summary(new Scholarship(['eligibility_rules' => ['provider_settings' => $providerProfile->scholarship_eligibility ?? []]])) ?? 'All education levels', 'coverage_areas' => collect($providerProfile->coverage_areas ?? [])->map(fn (array $area): string => $area['scope'] === 'nationwide' ? 'Nationwide' : implode(' / ', array_filter([$area['region_name'] ?? null, $area['province_name'] ?? null, $area['city_name'] ?? null])))->implode('; ')],
                'registration_version' => $providerProfile->registration_version,
                'documents_available' => $providerProfile->registration_version === 0 || app(ProviderRegistration::class)->documentsAreAvailable($providerProfile),
                'documents' => collect($providerProfile->verification_documents ?? [])->map(fn (array $document, string $kind): array => ['kind' => $kind, 'name' => $document['name'], 'mime' => $document['mime'], 'preview_url' => route('provider-verification.show', [$providerProfile, $kind, 'preview' => 1]), 'url' => route('provider-verification.show', [$providerProfile, $kind])])->values(),
                'verification_version' => $providerProfile->verification_version,
                'reviewed_at' => $providerProfile->reviewed_at?->toIso8601String(),
                'reviewer' => $providerProfile->reviewer?->name,
                'review_url' => route('admin.providers.review', $providerProfile),
            ],
            'history' => ActivityLog::query()->where('subject_type', ProviderProfile::class)
                ->where('subject_id', $providerProfile->id)
                ->whereIn('action', ['provider.reviewed', 'provider.details_changed'])
                ->with('actor:id,name')->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate(10, ['*'], 'history_page')->withQueryString()
                ->through(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'actor' => $log->actor?->name ?? 'Unavailable',
                    'action' => $log->action === 'provider.reviewed' ? 'Administrator decision' : 'Organization details changed',
                    'from' => $log->changes['from'] ?? null,
                    'to' => $log->changes['to'] ?? null,
                    'reason' => $log->changes['reason'] ?? null,
                    'previous_reason' => $log->changes['previous_rejection_reason'] ?? null,
                    'occurred_at' => $log->occurred_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(ReviewProviderRequest $request, ProviderProfile $providerProfile): RedirectResponse
    {
        $changed = DB::transaction(function () use ($request, $providerProfile): bool {
            $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($providerProfile->id);
            abort_unless($profile->user->role === UserRole::Provider, 403);
            if ($profile->verification_version !== (int) $request->validated('expected_version')) {
                throw ValidationException::withMessages([
                    'expected_version' => 'This provider account changed after you opened it. Reload the details and review the latest information before deciding.',
                ]);
            }
            $before = $profile->verification_status;
            $after = ProviderVerificationStatus::from($request->validated('verification_status'));
            if ($after === ProviderVerificationStatus::Approved && (! $profile->organization_name || ! $profile->contact_email)) {
                throw ValidationException::withMessages([
                    'verification_status' => 'The provider must complete their organization name and contact email before approval.',
                ]);
            }
            if ($after === ProviderVerificationStatus::Approved && $profile->registration_version > 0 && (! $request->boolean('documents_reviewed') || ! app(ProviderRegistration::class)->documentsAreAvailable($profile))) {
                throw ValidationException::withMessages(['verification_status' => 'Download and review the required documents, and confirm document review before approval.']);
            }
            if ($before === $after) {
                return false;
            }

            $previousRejectionReason = $profile->rejection_reason;
            $profile->verification_status = $after;
            $profile->reviewed_by = $request->user()->id;
            $profile->reviewed_at = now();
            $profile->rejection_reason = in_array($after, [ProviderVerificationStatus::Rejected, ProviderVerificationStatus::Suspended], true) ? $request->validated('rejection_reason') : null;
            $profile->verification_version++;
            $profile->save();

            $request->user()->activityLogs()->create([
                'action' => 'provider.reviewed', 'subject_type' => ProviderProfile::class, 'subject_id' => $profile->id,
                'changes' => ['from' => $before->value, 'to' => $after->value, 'reason' => $profile->rejection_reason, 'previous_rejection_reason' => $previousRejectionReason, 'version' => $profile->verification_version],
                'occurred_at' => $profile->reviewed_at,
            ]);
            $notice = new ProviderVerificationDecision($profile->id, $after->value, $profile->rejection_reason, $profile->reviewed_at->toIso8601String());
            $profile->user->notifyNow($notice, ['database']);
            $profile->user->notify($notice);
            app(AccountNotifications::class)->changed($profile->user);

            return true;
        });

        return to_route('admin.providers.show', $providerProfile)->with('status', $changed
            ? 'Provider decision saved. An account notification is available; email delivery is queued.'
            : 'This provider already has the selected status. No changes were made.');
    }

    private function providerData(ProviderProfile $profile): array
    {
        return [
            'id' => $profile->id, 'name' => $profile->user->name, 'email' => $profile->user->email,
            'organization_name' => $profile->organization_name, 'contact_email' => $profile->contact_email,
            'verification_status' => $profile->verification_status->value, 'rejection_reason' => $profile->rejection_reason,
            'created_at' => $profile->created_at->toIso8601String(),
            'show_url' => route('admin.providers.show', $profile),
        ];
    }
}
