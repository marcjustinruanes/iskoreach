<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, User $account): bool
    {
        return $this->viewAny($user) && in_array($account->role, [UserRole::Student, UserRole::Provider], true);
    }
}
