<?php

namespace App\Policies;

use App\Models\Renewal;
use App\Models\User;

class RenewalPolicy
{
    public function view(User $user, Renewal $renewal): bool
    {
        $application = $renewal->award->application;

        return $user->role->value === 'student' && $application->student_id === $user->id
        || $user->role->value === 'provider' && $application->scholarship->provider->user_id === $user->id
        && $application->scholarship->provider->verification_status->value === 'approved';
    }
}
