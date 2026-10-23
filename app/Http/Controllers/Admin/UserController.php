<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\ReviewStudentRegistrationRequest;
use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(ListUsersRequest $request): Response
    {
        $filters = [
            'q' => trim($request->validated('q') ?? ''),
            'role' => $request->validated('role') ?? '',
            'status' => $request->validated('status') ?? '',
            'sort' => $request->validated('sort') ?: 'newest',
        ];
        $query = User::query()->select('id', 'name', 'email', 'role', 'created_at')
            ->whereIn('role', [UserRole::Student->value, UserRole::Provider->value])
            ->with('providerProfile:id,user_id,organization_name,verification_status')
            ->with('studentProfile')
            ->when($filters['role'] !== '', fn ($query) => $query->where('role', $filters['role']))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$filters['q'].'%')->orWhereLike('email', '%'.$filters['q'].'%')
                ->orWhereHas('providerProfile', fn ($query) => $query->whereLike('organization_name', '%'.$filters['q'].'%'))));

        if ($filters['status'] === 'active') {
            $query->where(fn ($query) => $query->where(fn ($query) => $query->where('role', UserRole::Student->value)->whereHas('studentProfile', fn ($query) => $query->where('registration_status', 'approved')))
                ->orWhere(fn ($query) => $query->where('role', UserRole::Provider->value)
                    ->whereHas('providerProfile', fn ($query) => $query->where('verification_status', 'approved'))));
        } elseif ($filters['status'] === 'profile_missing') {
            $query->where('role', UserRole::Provider->value)->whereDoesntHave('providerProfile');
        } elseif ($filters['status'] !== '') {
            $query->where(fn ($query) => $query->whereHas('providerProfile', fn ($query) => $query->where('verification_status', $filters['status']))
                ->orWhereHas('studentProfile', fn ($query) => $query->where('registration_status', $filters['status'])));
        }

        [$column, $direction] = match ($filters['sort']) {
            'oldest' => ['created_at', 'asc'],
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            default => ['created_at', 'desc'],
        };

        return Inertia::render('admin/Users', [
            'filters' => $filters,
            'accounts' => $query->orderBy($column, $direction)->orderBy('id', $direction)->paginate(10)->withQueryString()
                ->through(fn (User $user): array => $this->accountData($user)),
        ]);
    }

    public function show(User $user): Response
    {
        Gate::authorize('view', $user);
        $user->load(['providerProfile:id,user_id,organization_name,verification_status', 'studentProfile']);
        $profile = $user->role === UserRole::Provider ? $user->providerProfile : $user->studentProfile;
        $profileType = $user->role === UserRole::Provider ? ProviderProfile::class : StudentProfile::class;

        return Inertia::render('admin/UserDetails', [
            'account' => [
                ...$this->accountData($user),
                'profile_present' => $profile !== null,
                'verification_url' => $user->role === UserRole::Provider && $profile ? route('admin.providers.show', $profile) : null,
                'registration_details' => $user->role === UserRole::Student ? $profile?->only(StudentRegistration::FIELDS) : null,
                'review_url' => $user->role === UserRole::Student && $profile ? route('admin.users.registration-review', $user) : null,
                'review_reason' => $user->role === UserRole::Student ? $profile?->registration_review_reason : null,
            ],
            'activity' => ActivityLog::query()->select('id', 'actor_id', 'action', 'occurred_at')
                ->whereIn('action', ['provider.reviewed', 'provider.details_changed', 'student.registration_reviewed'])
                ->where(fn ($query) => $query->where(fn ($query) => $query->where('subject_type', User::class)->where('subject_id', $user->id))
                    ->when($profile !== null, fn ($query) => $query->orWhere(fn ($query) => $query->where('subject_type', $profileType)->where('subject_id', $profile->id))))
                ->with('actor:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(10, ['*'], 'activity_page')->withQueryString()
                ->through(fn (ActivityLog $log): array => [
                    'id' => $log->id, 'actor' => $log->actor?->name ?? 'Unavailable',
                    'action' => match ($log->action) {
                        'student.registration_reviewed' => 'Student registration review saved', 'provider.reviewed' => 'Provider review saved', default => 'Organization details changed'
                    },
                    'occurred_at' => $log->occurred_at->toIso8601String(),
                ]),
        ]);
    }

    public function reviewStudent(ReviewStudentRegistrationRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('view', $user);
        abort_unless($user->role === UserRole::Student, 404);
        DB::transaction(function () use ($request, $user): void {
            $profile = $user->studentProfile()->lockForUpdate()->firstOrFail();
            $before = $profile->registration_status;
            $profile->registration_status = $request->validated('registration_status');
            $profile->registration_review_reason = $profile->registration_status === 'rejected' ? $request->validated('registration_review_reason') : null;
            $profile->save();
            $request->user()->activityLogs()->create([
                'action' => 'student.registration_reviewed', 'subject_type' => StudentProfile::class, 'subject_id' => $profile->id,
                'changes' => ['from' => $before, 'to' => $profile->registration_status, 'reason' => $profile->registration_review_reason], 'occurred_at' => now(),
            ]);
        });

        return to_route('admin.users.show', $user)->with('status', 'Student registration review saved.');
    }

    private function accountData(User $user): array
    {
        $status = $user->role === UserRole::Student ? match ($user->studentProfile?->registration_status) {
            'approved' => 'active', 'rejected' => 'rejected', default => 'pending'
        } : match ($user->providerProfile?->verification_status->value) {
            'approved' => 'active',
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'profile_missing',
        };

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value,
            'status' => $status, 'organization_name' => $user->role === UserRole::Provider ? $user->providerProfile?->organization_name : null,
            'created_at' => $user->created_at?->toIso8601String(), 'show_url' => route('admin.users.show', $user),
        ];
    }
}
