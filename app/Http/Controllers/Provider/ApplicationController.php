<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ReviewApplicationRequest;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Services\ApplicationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(Request $request, ApplicationWorkflow $workflow): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(ApplicationStatus::countedValues())], 'priority' => ['nullable', Rule::in(['standard', 'low'])]]);
        $filters = array_replace(['q' => '', 'status' => '', 'priority' => ''], array_filter($filters, fn ($value) => $value !== null));
        $query = Application::query()->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())
            ->whereHas('student', fn ($query) => $query->where('role', 'student'))
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->with('scholarship.provider');
        $query->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('submission_snapshot->student->name', '%'.$filters['q'].'%')->orWhereHas('scholarship', fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%'))))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['priority'] !== '', fn ($query) => $query->where('low_priority', $filters['priority'] === 'low'));

        return Inertia::render('provider/Applications', ['filters' => $filters, 'applications' => $query->latest('submitted_at')->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($item): array => [...$workflow->row($item), 'student_name' => $item->submission_snapshot['student']['name'] ?? 'Submitted account #'.$item->student_id])]);
    }

    public function show(Application $application, ApplicationWorkflow $workflow): Response
    {
        Gate::authorize('review', $application);

        return Inertia::render('provider/ApplicationDetails', ['application' => $workflow->details($application), 'reviewNotes' => ActivityLog::where('subject_type', Application::class)->where('subject_id', $application->id)->where('action', 'application.internal_note')->with('actor:id,name')->latest('id')->paginate(10, ['*'], 'notes_page')->withQueryString()->through(fn ($note): array => ['id' => $note->id, 'text' => $note->changes['note'], 'author' => $note->actor?->name ?? 'Former reviewer', 'created_at' => $note->occurred_at->toIso8601String()])]);
    }

    public function note(Request $request, Application $application): RedirectResponse
    {
        Gate::authorize('review', $application);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);
        $request->user()->activityLogs()->create([
            'action' => 'application.internal_note', 'subject_type' => Application::class,
            'subject_id' => $application->id, 'changes' => ['note' => $data['note']], 'occurred_at' => now(),
        ]);

        return to_route('provider.applications.show', $application)->with('status', 'Private review note saved.');
    }

    public function update(ReviewApplicationRequest $request, Application $application, ApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->review($request->user(), $application, $request->validated());

        return to_route('provider.applications.show', $application)->with('status', 'Application review saved. This decision does not create a scholarship award.');
    }
}
