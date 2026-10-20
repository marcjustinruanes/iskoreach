<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function view(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::Admin || $this->update($user, $profile);
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::Student && $user->id === $profile->user_id;
    }
}
