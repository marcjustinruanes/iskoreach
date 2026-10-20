<?php

namespace App\Policies;

use App\Models\ApplicationDocument;
use App\Models\User;

class ApplicationDocumentPolicy
{
    public function view(User $user, ApplicationDocument $document): bool
    {
        return $user->can('view', $document->application) || ($document->submitted_at !== null && $document->removed_at === null && $user->can('review', $document->application));
    }
}
