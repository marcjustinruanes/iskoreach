<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\StudentDocument;
use App\Models\User;

class StudentDocumentPolicy
{
    public function view(User $user, StudentDocument $document): bool
    {
        return $user->role === UserRole::Student && $document->student_id === $user->id && ! $document->trashed();
    }
}
