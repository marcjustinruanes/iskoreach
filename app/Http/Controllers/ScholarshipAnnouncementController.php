<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Models\ScholarshipAnnouncement;
use App\Models\User;
use App\Services\ScholarshipCatalog;
use App\Services\ScholarshipNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScholarshipAnnouncementController extends Controller
{
    public function index(Request $request, ScholarshipCatalog $catalog): Response
    {
        $provider = $request->user()->role->value === 'provider';
        $programs = $catalog->visible()->when($provider, fn ($query) => $query->where('provider_id', $request->user()->providerProfile?->id));

        return Inertia::render('Announcements', ['announcements' => ScholarshipAnnouncement::whereIn('scholarship_id', (clone $programs)->select('id'))->latest('id')->paginate(20)->through(fn (ScholarshipAnnouncement $item): array => $item->only('id', 'scholarship_id', 'title', 'body', 'created_at')),
            'scholarships' => $provider ? $programs->get(['id', 'title']) : []]);
    }

    public function store(Request $request, Scholarship $scholarship, ScholarshipNotices $notices): RedirectResponse
    {
        Gate::authorize('manage', $scholarship);
        abort_unless($scholarship->provider->isApproved() && $scholarship->published_at !== null && in_array($scholarship->status->value, ['published', 'closed'], true), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'body' => ['required', 'string', 'max:6000'], 'submission_token' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $scholarship, $notices, $data): void {
            Scholarship::whereKey($scholarship->id)->lockForUpdate()->firstOrFail();
            $existing = ScholarshipAnnouncement::where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                if ($existing->scholarship_id !== $scholarship->id || $existing->title !== $data['title'] || $existing->body !== $data['body']) {
                    throw ValidationException::withMessages(['submission_token' => 'This announcement token was already used.']);
                }

                return;
            }
            $notice = new ScholarshipAnnouncement;
            $notice->forceFill($data + ['scholarship_id' => $scholarship->id, 'published_by' => $request->user()->id])->save();
            User::where('role', 'student')->orderBy('id')->eachById(fn (User $student) => $notices->send($student, $scholarship, 'Scholarship announcement', $scholarship->title.': '.$data['title']));
        });

        return back()->with('status', 'Announcement published and student inboxes notified.');
    }
}
