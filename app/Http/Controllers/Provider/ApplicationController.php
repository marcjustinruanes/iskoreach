<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ReviewApplicationRequest;
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
        $query->when($filters['q'] !== '', fn ($query) => $query->whereHas('scholarship', fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%')))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['priority'] !== '', fn ($query) => $query->where('low_priority', $filters['priority'] === 'low'));

        return Inertia::render('provider/Applications', ['filters' => $filters, 'applications' => $query->latest('submitted_at')->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($item): array => [...$workflow->row($item), 'student_name' => $item->submission_snapshot['student']['name'] ?? 'Submitted account #'.$item->student_id])]);
    }

    public function show(Application $application, ApplicationWorkflow $workflow): Response
    {
        Gate::authorize('review', $application);

        return Inertia::render('provider/ApplicationDetails', ['application' => $workflow->details($application)]);
    }

    public function update(ReviewApplicationRequest $request, Application $application, ApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->review($request->user(), $application, $request->validated());

        return to_route('provider.applications.show', $application)->with('status', 'Application review saved. This decision does not create a scholarship award.');
    }
}
