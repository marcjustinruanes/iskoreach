<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ReviewApplicationRequest;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Scholarship;
use App\Services\ApplicationWorkflow;
use App\Services\StudentRegistration;
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
            'school' => ['nullable', 'string', 'max:255'], 'campus_city_name' => ['nullable', 'string', 'max:255'], 'campus_location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(ApplicationStatus::countedValues())], 'priority' => ['nullable', Rule::in(['standard', 'low', 'academic'])]]);
        $filters = array_replace(['q' => '', 'status' => '', 'priority' => '', 'school' => '', 'campus_city_name' => '', 'campus_location' => ''], array_filter($filters, fn ($value) => $value !== null));
        $query = Application::query()->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())
            ->withValidApplicant()
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->with('scholarship.provider');
        $campusOptions = (clone $query)->get(['submission_snapshot'])->map(fn ($item): array => [
            'school' => $item->submission_snapshot['student']['school'] ?? null,
            'campus' => app(StudentRegistration::class)->schoolLocation($item->submission_snapshot['student'] ?? []),
            'region_name' => $item->submission_snapshot['student']['campus_region_name'] ?? null,
            'province_name' => $item->submission_snapshot['student']['campus_province_name'] ?? null,
            'city_name' => $item->submission_snapshot['student']['campus_city_name'] ?? null,
        ])->filter(fn (array $option): bool => is_string($option['school']) && $option['school'] !== '');
        $schoolOptions = $campusOptions->pluck('school')->unique()->sort()->values()->all();
        $campusOptions = $campusOptions->filter(fn (array $option): bool => filled($option['campus']) && filled($option['city_name']))->unique(fn (array $option): string => $option['school'].'|'.$option['campus'])->sortBy('campus')->values()->all();
        $query->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('submission_snapshot->student->name', '%'.$filters['q'].'%')->orWhereHas('scholarship', fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%'))))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['priority'] === 'academic', fn ($query) => $query->where('submission_snapshot->academic_priority', true))
            ->when(in_array($filters['priority'], ['standard', 'low'], true), fn ($query) => $query->where('low_priority', $filters['priority'] === 'low'))
            ->when($filters['school'] !== '', fn ($query) => $query->where('submission_snapshot->student->school', $filters['school']))
            ->when($filters['campus_location'] !== '', function ($query) use ($filters, $campusOptions): void {
                $matches = collect($campusOptions)->where('campus', $filters['campus_location']);
                if ($matches->isEmpty()) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->where(function ($query) use ($matches): void {
                    foreach ($matches as $match) {
                        $query->orWhere(fn ($query) => $query->where('submission_snapshot->student->campus_city_name', $match['city_name'])
                            ->where('submission_snapshot->student->campus_province_name', $match['province_name'])->where('submission_snapshot->student->campus_region_name', $match['region_name']));
                    }
                });
            })
            ->when($filters['campus_city_name'] !== '', fn ($query) => $query->where('submission_snapshot->student->campus_city_name', $filters['campus_city_name']));

        return Inertia::render('provider/Applications', ['scholarships' => Scholarship::where('provider_id', $request->user()->providerProfile->id)->orderBy('title')->get(['id', 'title']), 'schoolOptions' => $schoolOptions, 'campusOptions' => $campusOptions, 'filters' => $filters, 'applications' => $query->latest('submitted_at')->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($item): array => [...$workflow->row($item), 'school' => $item->submission_snapshot['student']['school'] ?? null, 'campus' => app(StudentRegistration::class)->schoolLocation($item->submission_snapshot['student'] ?? []), 'student_name' => $item->applicant_source === 'external' ? $item->applicant_name : ($item->submission_snapshot['student']['name'] ?? 'Submitted account #'.$item->student_id)])]);
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
