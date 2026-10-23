<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Notifications\ProviderVerificationDecision;
use App\Services\AccountNotifications;
use App\Services\GoogleAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => [
                'user' => $request->user() ? [
                    ...$request->user()->only('id', 'name', 'email', 'role'),
                    'photo_url' => ($path = $request->user()->studentProfile?->photo_path) && Storage::disk('profile_pictures')->exists($path) ? Storage::disk('profile_pictures')->url($path) : null,
                ] : null,
                'provider_status' => $request->user()?->role === UserRole::Provider
                    ? $request->user()->providerProfile?->verification_status->value : null,
            ],
            'googleAuth' => fn () => ['enabled' => app(GoogleAuthentication::class)->configured(), 'workUrl' => route('google.redirect', ['intent' => 'provider-work']), 'loginUrl' => route('google.redirect'), 'studentUrl' => route('google.redirect', ['intent' => 'student']), 'providerUrl' => route('google.redirect', ['intent' => 'provider'])],
            'status' => fn () => $request->session()->get('status'),
            'notificationSummary' => fn () => $request->user() ? app(AccountNotifications::class)->summary($request->user()) : null,
            ...($request->user()?->role === UserRole::Provider ? [
                'providerContext' => function () use ($request): array {
                    $profile = $request->user()->providerProfile;

                    return [
                        'organization_name' => $profile?->organization_name,
                        'verification_status' => $profile?->verification_status->value,
                        'dashboard' => route('provider.dashboard'), 'profile' => route('provider.profile'),
                        'counts' => route('provider.applicant-counts.index'), 'workspace' => route('provider.workspace'),
                        'status' => route('provider.status'),
                        'notices' => $request->user()->notifications()->where('type', ProviderVerificationDecision::class)->latest('created_at')->orderByDesc('id')->limit(5)->get()
                            ->map(fn ($notice): array => ['id' => $notice->id, 'status' => $notice->data['status'],
                                'reason' => $notice->data['reason'] ?? null, 'decided_at' => $notice->data['decided_at']]),
                    ];
                },
            ] : ['providerContext' => null]),
            ...($request->user()?->role === UserRole::Admin ? [
                'adminNavigation' => fn (): array => [
                    'dashboard' => route('admin.dashboard'),
                    'providers' => route('admin.providers.index'),
                    'users' => route('admin.users.index'),
                    'scholarships' => route('admin.scholarships.index'),
                    'counts' => route('admin.applicant-counts.index'),
                    'awards' => route('admin.awards.index'),
                    'renewals' => route('admin.renewals.index'),
                    'releases' => route('admin.releases.index'),
                    'terms' => route('admin.academic-terms.index'),
                    'policies' => route('admin.policies.index'),
                    'reports' => route('admin.reports.index'),
                    'activity' => route('admin.activity-logs.index'),
                    'home' => route('home'),
                    'logout' => route('logout'),
                    'pendingProviders' => ProviderProfile::query()->where('verification_status', 'pending')
                        ->whereHas('user', fn ($query) => $query->where('role', UserRole::Provider->value))->count(),
                ],
            ] : []),
        ];
    }
}
