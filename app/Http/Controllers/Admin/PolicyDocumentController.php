<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePolicyDocumentRequest;
use App\Models\ActivityLog;
use App\Models\PolicyDocument;
use App\Services\RegistrationPolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PolicyDocumentController extends Controller
{
    public function index(RegistrationPolicies $policies): Response
    {
        return Inertia::render('admin/Policies', ['documents' => collect(['terms', 'privacy'])->map(function (string $kind) use ($policies): array {
            $latest = PolicyDocument::query()->where('kind', $kind)->latest('id')->firstOrFail();
            $published = $policies->published($kind);

            return ['kind' => $kind, 'title' => $latest->title, 'content' => $latest->content, 'expected_version' => $latest->id,
                'published_version' => $published->version(), 'published_at' => $published->published_at->toIso8601String(),
                'is_draft' => $latest->published_at === null, 'save_url' => route('admin.policies.store', $kind), 'public_url' => route($kind)];
        })->all()]);
    }

    public function store(SavePolicyDocumentRequest $request, string $kind): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data, $kind): void {
            PolicyDocument::query()->where('kind', $kind)->orderBy('id')->lockForUpdate()->firstOrFail();
            $latest = PolicyDocument::query()->where('kind', $kind)->latest('id')->firstOrFail();
            if ($latest->id !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Another administrator updated this policy. Reload the editor before saving.']);
            }
            $document = PolicyDocument::query()->create(['kind' => $kind, 'title' => $data['title'], 'content' => $data['content'],
                'published_at' => $data['action'] === 'publish' ? now() : null, 'created_by' => $request->user()->id]);
            $log = new ActivityLog(['action' => 'policy.'.$data['action'], 'changes' => ['kind' => $kind, 'version' => $document->version()], 'occurred_at' => now()]);
            $log->actor()->associate($request->user());
            $log->subject()->associate($document);
            $log->save();
        });

        return back()->with('status', $data['action'] === 'publish' ? 'Policy published. Registration now uses this version.' : 'Draft saved. The published policy is unchanged.');
    }
}
