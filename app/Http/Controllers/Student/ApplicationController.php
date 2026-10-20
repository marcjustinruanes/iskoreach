<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SubmitApplicationRequest;
use App\Models\Application;
use App\Models\Scholarship;
use App\Models\StudentDocument;
use App\Services\ApplicationWorkflow;
use App\Services\PrivateDocuments;
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
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_column(ApplicationStatus::cases(), 'value'))]]);
        $status = $filters['status'] ?? '';

        return Inertia::render('student/Applications', ['filters' => ['status' => $status],
            'applications' => Application::withTrashed()->where('student_id', $request->user()->id)->with('scholarship.provider')
                ->when($status !== '', fn ($query) => $query->where('status', $status))->latest('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()->through($workflow->row(...))]);
    }

    public function store(Request $request, Scholarship $scholarship, ApplicationWorkflow $workflow): RedirectResponse
    {
        $item = $workflow->start($request->user(), $scholarship);

        return to_route('student.applications.show', $item)->with('status', 'Your application draft is ready. Review it before submitting.');
    }

    public function show(Request $request, Application $application, ApplicationWorkflow $workflow): Response
    {
        Gate::authorize('view', $application);

        return Inertia::render('student/ApplicationDetails', ['application' => $workflow->details($application),
            'checklist' => app(PrivateDocuments::class)->checklist($application),
            'vault' => StudentDocument::where('student_id', $request->user()->id)->latest()->get()->map(app(PrivateDocuments::class)->vaultData(...)),
            'context' => $workflow->context($request->user(), $application->scholarship)]);
    }

    public function submit(SubmitApplicationRequest $request, Application $application, ApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->submit($request->user(), $application, $request->validated());

        return to_route('student.applications.show', $application)->with('status', 'Application submitted. Your provider can now review it.');
    }
}
