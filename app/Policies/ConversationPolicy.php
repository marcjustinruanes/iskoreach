<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->student()->where('role', 'student')->exists()
            && $conversation->provider()->where('verification_status', 'approved')->whereHas('user', fn ($query) => $query->where('role', 'provider'))->exists()
            && (($user->role === UserRole::Student && $user->id === $conversation->student_id)
            || ($user->role === UserRole::Provider && $conversation->provider()->where('user_id', $user->id)->exists()));
    }
}
