<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Scholarship;
use App\Services\ApplicantCounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantCountController extends Controller
{
    public function index(Request $request, ApplicantCounts $counts): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $search = trim($filters['q'] ?? '');
        $query = Scholarship::query()->whereHas('provider', fn ($query) => $query->where('user_id', $request->user()->id));

        return Inertia::render('provider/ApplicantCounts', ['filters' => ['q' => $search], 'scholarships' => $query->with('provider')
            ->when($search !== '', fn ($query) => $query->whereLike('title', '%'.$search.'%'))->latest('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()
            ->through(fn (Scholarship $program): array => ['id' => $program->id, 'title' => $program->title, 'provider' => $program->provider->organization_name,
                'summary' => $counts->summary($program), 'show_url' => route('provider.applicant-counts.show', $program)])]);
    }

    public function show(Scholarship $scholarship, ApplicantCounts $counts): Response
    {
        Gate::authorize('reportExternalCount', $scholarship);

        return Inertia::render('provider/ApplicantCountDetails', [
            'scholarship' => ['id' => $scholarship->id, 'title' => $scholarship->title, 'provider' => $scholarship->provider->organization_name],
            'summary' => $counts->summary($scholarship),
            'applicants' => $scholarship->applications()->withValidApplicant()->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())->latest('submitted_at')->orderByDesc('id')->paginate(20)
                ->through(fn ($application): array => ['id' => $application->id, 'name' => $application->applicant_source === 'external' ? $application->applicant_name : ($application->submission_snapshot['student']['name'] ?? 'Submitted account #'.$application->student_id), 'email' => $application->applicant_source === 'external' ? $application->applicant_email : null,
                    'source' => $application->applicant_source, 'status' => $application->status->value, 'url' => route('provider.applications.show', $application)]),
        ]);
    }
}
