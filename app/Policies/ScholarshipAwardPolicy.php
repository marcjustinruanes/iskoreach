<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ScholarshipAward;
use App\Models\User;

class ScholarshipAwardPolicy
{
    public function view(User $user, ScholarshipAward $award): bool
    {
        return $user->role === UserRole::Student && $award->application->student_id === $user->id || $this->manage($user, $award);
    }

    public function manage(User $user, ScholarshipAward $award): bool
    {
        $provider = $award->application->scholarship->provider;

        return $user->role === UserRole::Provider && $provider->user_id === $user->id && $provider->isApproved();
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function publish(User $user, ScholarshipAward $award): bool
    {
        return $this->viewAny($user);
    }

    public function consent(User $user, ScholarshipAward $award): bool
    {
        return $user->role === UserRole::Student && $award->application->student_id === $user->id;
    }
}
