<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $user->role === UserRole::Student && $application->student_id === $user->id;
    }

    public function review(User $user, Application $application): bool
    {
        return $user->role === UserRole::Provider && ! $application->trashed() && $application->submitted_at !== null
            && in_array($application->status->value, ApplicationStatus::countedValues(), true)
            && ($application->applicant_source === 'external' || $application->student?->role === UserRole::Student)
            && $application->scholarship->provider()->where('user_id', $user->id)->where('verification_status', 'approved')->exists();
    }
}
