<?php

namespace App\Http\Controllers;

use App\Services\AccountNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, AccountNotifications $notifications): Response
    {
        $filters = $request->validate(['filter' => ['nullable', Rule::in(['all', 'unread', 'read'])]]);
        $filter = $filters['filter'] ?? 'all';
        $query = $notifications->query($request->user());
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        return Inertia::render('Notifications', ['filter' => $filter, 'notifications' => $query->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($notice) => $notifications->row($request->user(), $notice))]);
    }

    public function summary(Request $request, AccountNotifications $notifications): JsonResponse
    {
        return response()->json($notifications->summary($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification, AccountNotifications $notifications): RedirectResponse
    {
        DB::transaction(function () use ($request, $notification, $notifications): void {
            $notice = $notifications->query($request->user())->whereKey($notification)->lockForUpdate()->firstOrFail();
            if ($notice->read_at === null) {
                $notice->markAsRead();
                $notifications->changed($request->user());
            }
        });

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request, AccountNotifications $notifications): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $notifications): void {
            $notifications->query($request->user())->whereNull('read_at')->update(['read_at' => now()]);
            $notifications->changed($request->user());
        });

        return back()->with('status', 'Your current notifications are marked as read.');
    }
}
