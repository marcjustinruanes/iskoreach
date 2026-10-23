<?php

namespace App\Services;

use App\Events\ChatChanged;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Notifications\ChatActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PrivateChat
{
    public function query(User $user): Builder
    {
        return Conversation::query()->whereHas('student', fn ($query) => $query->where('role', 'student'))
            ->whereHas('provider', fn ($query) => $query->where('verification_status', 'approved')->whereHas('user', fn ($query) => $query->where('role', 'provider')))
            ->where(function ($query) use ($user): void {
                if ($user->role->value === 'student') {
                    $query->where('student_id', $user->id);
                } elseif ($user->role->value === 'provider') {
                    $query->whereHas('provider', fn ($query) => $query->where('user_id', $user->id));
                } else {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    public function start(User $user, Application $application): Conversation
    {
        return DB::transaction(function () use ($user, $application): Conversation {
            $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($application->scholarship->provider_id);
            User::query()->lockForUpdate()->findOrFail($application->student_id);
            abort_unless($application->submitted_at && ! $application->trashed() && ! $application->scholarship->trashed()
                && $profile->isApproved() && ($user->can('view', $application) || $user->can('review', $application)), 403);
            $conversation = Conversation::firstOrCreate(['student_id' => $application->student_id, 'provider_id' => $profile->id]);
            Gate::forUser($user)->authorize('view', $conversation);

            return $conversation;
        });
    }

    public function send(User $user, Conversation $conversation, array $data, ?UploadedFile $file): Message
    {
        $path = null;
        $used = false;
        try {
            if ($file) {
                $path = $file->store('chat/'.$user->id, 'documents');
            }

            return DB::transaction(function () use ($user, $conversation, $data, $file, $path, &$used): Message {
                $thread = $this->lock($user, $conversation);
                $body = trim($data['body'] ?? '');
                $body = $body === '' ? null : $body;
                if ($body === null && ! $file) {
                    throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
                }
                $existing = $thread->messages()->where('sender_id', $user->id)->where('submission_token', $data['submission_token'])->first();
                if ($existing) {
                    $sameFile = $file ? $existing->attachment_path && hash_equals($existing->attachment_checksum, hash_file('sha256', $file->getRealPath())) : ! $existing->attachment_path;
                    if ($existing->body !== $body || ! $sameFile) {
                        throw ValidationException::withMessages(['submission_token' => 'This send attempt was already recorded. Reload before composing another message.']);
                    }

                    return $existing;
                }
                $message = $thread->messages()->create(['sender_id' => $user->id, 'body' => $body, 'submission_token' => $data['submission_token']]);
                if ($file) {
                    $message->forceFill(['attachment_path' => $path, 'attachment_original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $file->getClientOriginalName()), 0, 240),
                        'attachment_mime_type' => $file->getMimeType(), 'attachment_size_bytes' => $file->getSize(), 'attachment_checksum' => hash_file('sha256', $file->getRealPath()), ])->save();
                }
                $thread->last_message_at = now();
                $thread->save();
                Cache::forget($this->typingKey($thread, $user->id));
                $recipient = $user->id === $thread->student_id ? $thread->provider->user : $thread->student;
                $recipient->notifyNow(new ChatActivity($thread->id, $message->id), ['database']);
                app(AccountNotifications::class)->changed($recipient);
                $this->changed($thread);
                $used = true;

                return $message;
            });
        } finally {
            if ($path && ! $used) {
                Storage::disk('documents')->delete($path);
            }
        }
    }

    public function read(User $user, Conversation $conversation, int $throughId): void
    {
        DB::transaction(function () use ($user, $conversation, $throughId): void {
            $thread = $this->lock($user, $conversation);
            abort_unless($thread->messages()->whereKey($throughId)->exists(), 422, 'The read boundary is not in this conversation.');
            $thread->messages()->where('id', '<=', $throughId)->where('sender_id', '!=', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
            $user->notifications()->where('type', ChatActivity::class)->whereNull('read_at')->where('data->conversation_id', $thread->id)->where('data->message_id', '<=', $throughId)->update(['read_at' => now()]);
            app(AccountNotifications::class)->changed($user);
            $this->changed($thread);
        });
    }

    public function typing(User $user, Conversation $conversation, bool $typing): void
    {
        Gate::forUser($user)->authorize('view', $conversation);
        if ($typing) {
            Cache::put($this->typingKey($conversation, $user->id), true, now()->addSeconds(5));
        } else {
            Cache::forget($this->typingKey($conversation, $user->id));
        }
        $this->changed($conversation);
    }

    public function state(User $user, Conversation $conversation, int $page = 1): array
    {
        Gate::forUser($user)->authorize('view', $conversation);
        $other = $user->id === $conversation->student_id ? $conversation->provider->user_id : $conversation->student_id;

        return ['thread' => $this->threadData($user, $conversation), 'typing' => (bool) Cache::get($this->typingKey($conversation, $other), false),
            'messages' => $conversation->messages()->orderByDesc('id')->paginate(25, ['*'], 'page', $page)->withPath(route('messages.show', $conversation))->through(fn ($message) => [
                'id' => $message->id, 'mine' => $message->sender_id === $user->id, 'body' => $message->body, 'sent_at' => $message->created_at->toIso8601String(),
                'read_at' => $message->read_at?->toIso8601String(), 'attachment' => $message->attachment_path ? ['name' => $message->attachment_original_name,
                    'size_bytes' => $message->attachment_size_bytes, 'download_url' => route('messages.download', $message)] : null])];
    }

    public function threadData(User $user, Conversation $thread): array
    {
        return ['id' => $thread->id, 'partner' => $user->role->value === 'student' ? $thread->provider->organization_name : $thread->student->name,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'unread' => $thread->messages()->where('sender_id', '!=', $user->id)->whereNull('read_at')->count()];
    }

    private function lock(User $user, Conversation $conversation): Conversation
    {
        ProviderProfile::query()->lockForUpdate()->findOrFail($conversation->provider_id);
        $thread = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
        Gate::forUser($user)->authorize('view', $thread);

        return $thread;
    }

    private function typingKey(Conversation $thread, int $sender): string
    {
        return 'chat:typing:'.$thread->id.':'.$sender;
    }

    private function changed(Conversation $thread): void
    {
        if (config('broadcasting.default') === 'reverb') {
            event(new ChatChanged($thread->id));
        }
    }
}
