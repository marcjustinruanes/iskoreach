<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Scholarship;
use App\Models\User;

class ScholarshipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, Scholarship $scholarship): bool
    {
        return $this->viewAny($user);
    }

    public function reportExternalCount(User $user, Scholarship $scholarship): bool
    {
        return $user->role === UserRole::Provider && ! $scholarship->trashed()
            && $scholarship->provider()->where('user_id', $user->id)->where('verification_status', 'approved')->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Provider && $user->providerProfile?->isApproved();
    }

    public function manage(User $user, Scholarship $scholarship): bool
    {
        return $this->reportExternalCount($user, $scholarship);
    }
}
