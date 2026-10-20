<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\User;

class ProviderProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, ProviderProfile $profile): bool
    {
        return $user->role === UserRole::Admin || $this->update($user, $profile);
    }

    public function update(User $user, ProviderProfile $profile): bool
    {
        return $user->role === UserRole::Provider && $user->id === $profile->user_id;
    }

    public function review(User $user, ProviderProfile $profile): bool
    {
        return $user->role === UserRole::Admin && $user->id !== $profile->user_id;
    }

    public function accessWorkspace(User $user, ProviderProfile $profile): bool
    {
        return $this->update($user, $profile) && $profile->isApproved();
    }
}
