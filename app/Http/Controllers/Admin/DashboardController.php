<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        Gate::authorize('access-admin');

        $providers = ProviderProfile::query()->whereHas('user', fn ($query) => $query->where('role', UserRole::Provider->value));
        $statuses = (clone $providers)->selectRaw('verification_status, COUNT(*) AS total')
            ->groupBy('verification_status')->pluck('total', 'verification_status');
        $studentCount = User::query()->where('role', UserRole::Student)->count();
        $providerCount = User::query()->where('role', UserRole::Provider)->count();

        return Inertia::render('admin/Dashboard', [
            'summary' => [
                'applications' => Application::withTrashed()->whereNotNull('submitted_at')->whereIn('status', ['submitted', 'under_review', 'accepted', 'rejected', 'waitlisted'])->whereHas('student', fn ($query) => $query->where('role', UserRole::Student))->count(),
                'active_awards' => ScholarshipAward::query()->active()->count(),
                'pending_renewals' => Renewal::query()->where('status', 'submitted')->count(),
                'pending_releases' => BenefitRelease::query()->whereIn('status', ['pending', 'processing', 'delayed', 'failed'])->count(),
                'scholarships' => Scholarship::withTrashed()->count(),
                'published_scholarships' => Scholarship::query()->where('status', 'published')->count(),
                'students' => $studentCount,
                'providers' => $providerCount,
                'pending' => (int) ($statuses['pending'] ?? 0),
                'approved' => (int) ($statuses['approved'] ?? 0),
                'rejected' => (int) ($statuses['rejected'] ?? 0),
                'missing_provider_profiles' => $providerCount - (clone $providers)->count(),
                'students_last_30_days' => User::query()->where('role', UserRole::Student)->where('created_at', '>=', now()->subDays(30))->count(),
                'providers_last_30_days' => User::query()->where('role', UserRole::Provider)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'recentProviders' => (clone $providers)->latest('created_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (ProviderProfile $profile): array => [
                    'id' => $profile->id,
                    'organization_name' => $profile->organization_name,
                    'verification_status' => $profile->verification_status->value,
                    'created_at' => $profile->created_at->toIso8601String(),
                ]),
            'recentActivity' => ActivityLog::query()
                ->whereHas('actor', fn ($query) => $query->where('role', UserRole::Admin->value))
                ->with('actor:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit(6)->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'actor' => $log->actor->name,
                    'action' => match ($log->action) {
                        'provider.reviewed' => 'Provider review saved',
                        'admin.created_locally' => 'Administrator provisioned locally',
                        'external_count.approve' => 'External applicant count verified',
                        'external_count.reject' => 'External applicant count rejected',
                        'external_count.correct' => 'External applicant count corrected',
                        'external_count.reverify' => 'External applicant count re-verified',
                        'award.publish' => 'Award approved for public listing',
                        'award.unpublish' => 'Award removed from public listing',
                        default => 'Administrative action recorded',
                    },
                    'reference' => match ($log->subject_type) {
                        ProviderProfile::class => 'Provider account #'.$log->subject_id,
                        User::class => 'Account #'.$log->subject_id,
                        ExternalApplicantCount::class => 'External count report #'.$log->subject_id,
                        ScholarshipAward::class => 'Award #'.$log->subject_id,
                        default => 'Platform record',
                    },
                    'occurred_at' => $log->occurred_at->toIso8601String(),
                ]),
            'updatedAt' => now()->toIso8601String(),
            'recentScholarships' => Scholarship::withTrashed()->with('provider:id,organization_name')->latest('updated_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Scholarship $scholarship): array => [
                    'id' => $scholarship->id, 'title' => $scholarship->title,
                    'provider' => $scholarship->provider->organization_name ?: 'Organization #'.$scholarship->provider_id,
                    'status' => $scholarship->status->value, 'deleted_at' => $scholarship->deleted_at?->toIso8601String(),
                    'show_url' => route('admin.scholarships.show', $scholarship),
                ]),
        ]);
    }
}
