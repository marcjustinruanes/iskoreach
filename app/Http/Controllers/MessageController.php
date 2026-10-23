<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadMessagesRequest;
use App\Http\Requests\SendMessageRequest;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\PrivateChat;
use App\Services\PrivateDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function index(Request $request, PrivateChat $chat): Response
    {
        $search = trim($request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '');

        return Inertia::render('messages/Index', ['filters' => ['q' => $search], 'threads' => $chat->query($request->user())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$search.'%'))
                ->orWhereHas('student', fn ($query) => $query->whereLike('name', '%'.$search.'%'))))
            ->with(['provider', 'student'])->orderByDesc('last_message_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($thread) => $chat->threadData($request->user(), $thread))]);
    }

    public function start(Request $request, Application $application, PrivateChat $chat): RedirectResponse
    {
        $thread = $chat->start($request->user(), $application);

        return to_route('messages.show', $thread);
    }

    public function show(Request $request, Conversation $conversation, PrivateChat $chat): Response
    {
        $page = $request->validate(['page' => ['nullable', 'integer', 'min:1']])['page'] ?? 1;

        return Inertia::render('messages/Thread', [...$chat->state($request->user(), $conversation, (int) $page), 'upload_limit_kb' => app(PrivateDocuments::class)->uploadLimitKilobytes()]);
    }

    public function state(Request $request, Conversation $conversation, PrivateChat $chat): JsonResponse
    {
        return response()->json($chat->state($request->user(), $conversation))->header('Cache-Control', 'private, no-store');
    }

    public function send(SendMessageRequest $request, Conversation $conversation, PrivateChat $chat): RedirectResponse
    {
        $chat->send($request->user(), $conversation, $request->validated(), $request->file('file'));

        return to_route('messages.show', $conversation)->with('status', 'Message sent.');
    }

    public function read(ReadMessagesRequest $request, Conversation $conversation, PrivateChat $chat): JsonResponse
    {
        $chat->read($request->user(), $conversation, (int) $request->validated('through_id'));

        return response()->json(['ok' => true]);
    }

    public function typing(Request $request, Conversation $conversation, PrivateChat $chat): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['typing' => ['required', 'boolean']]);
        $chat->typing($request->user(), $conversation, $data['typing']);

        return response()->json(['ok' => true]);
    }

    public function download(Message $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->conversation);
        $disk = Storage::disk('documents');
        abort_unless($attachment->attachment_path && $disk->exists($attachment->attachment_path) && hash_equals($attachment->attachment_checksum, hash_file('sha256', $disk->path($attachment->attachment_path))), 404);

        return $disk->download($attachment->attachment_path, $attachment->attachment_original_name, ['Content-Type' => $attachment->attachment_mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
