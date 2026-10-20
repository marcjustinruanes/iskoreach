<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id) => (int) $user->id === (int) $id);
Broadcast::channel('chat.{id}', fn (User $user, string $id) => ctype_digit($id) && ($conversation = Conversation::find($id)) && $user->can('view', $conversation));
