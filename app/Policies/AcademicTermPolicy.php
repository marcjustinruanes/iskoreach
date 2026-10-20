<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\User;

class AcademicTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, AcademicTerm $term): bool
    {
        return $user->role === UserRole::Admin;
    }
}
