<?php

namespace App\Http\Controllers\Provider;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ReportApplicantCountRequest;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\ApplicantCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantCountController extends Controller
{
    public function index(Request $request, ApplicantCounts $counts): Response
    {
        return Inertia::render('provider/ApplicantCounts', [
            'scholarships' => Scholarship::query()->whereHas('provider', fn ($query) => $query->where('user_id', $request->user()->id))
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)
                ->through(fn (Scholarship $scholarship): array => [
                    'id' => $scholarship->id, 'title' => $scholarship->title,
                    'show_url' => route('provider.applicant-counts.show', $scholarship), 'summary' => $counts->summary($scholarship),
                ]),
        ]);
    }

    public function show(Scholarship $scholarship, ApplicantCounts $counts): Response
    {
        Gate::authorize('reportExternalCount', $scholarship);

        return Inertia::render('provider/ApplicantCountDetails', [
            'scholarship' => ['id' => $scholarship->id, 'title' => $scholarship->title, 'report_url' => route('provider.applicant-counts.store', $scholarship)],
            'summary' => $counts->summary($scholarship),
            'reports' => $scholarship->externalCounts()->with(['reporter:id,name', 'reviewer:id,name'])->orderByDesc('id')->paginate(10)->withQueryString()
                ->through(fn (ExternalApplicantCount $report): array => $counts->reportData($report)),
        ]);
    }

    public function store(ReportApplicantCountRequest $request, Scholarship $scholarship): RedirectResponse
    {
        DB::transaction(function () use ($request, $scholarship): void {
            $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($scholarship->provider_id);
            abort_unless($request->user()->role === UserRole::Provider && $profile->user_id === $request->user()->id && $profile->isApproved(), 403);
            $locked = Scholarship::query()->lockForUpdate()->findOrFail($scholarship->id);
            $data = $request->validated();
            $existing = ExternalApplicantCount::query()->where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                if ($existing->scholarship_id !== $locked->id || $existing->reported_by !== $request->user()->id) {
                    throw ValidationException::withMessages(['submission_token' => 'This submission identifier is already in use. Reload the form.']);
                }
                if ($existing->reported_count !== (int) $data['reported_count'] || $existing->source_label !== $data['source_label'] || $existing->source_as_of->toDateString() !== $data['source_as_of']) {
                    throw ValidationException::withMessages(['submission_token' => 'The original report was already received with different values. Reload the form to create a new report.']);
                }

                return;
            }
            $report = new ExternalApplicantCount;
            $report->forceFill([
                'scholarship_id' => $locked->id, 'reported_count' => $data['reported_count'], 'source_label' => $data['source_label'],
                'source_as_of' => $data['source_as_of'], 'reported_by' => $request->user()->id,
                'duplicates_excluded' => true, 'submission_token' => $data['submission_token'], 'status' => 'pending',
            ])->save();
            $request->user()->activityLogs()->create([
                'action' => 'external_count.reported', 'subject_type' => ExternalApplicantCount::class, 'subject_id' => $report->id,
                'changes' => ['scholarship_id' => $locked->id, 'reported_count' => $report->reported_count, 'source_as_of' => $data['source_as_of']],
                'occurred_at' => now(),
            ]);
        });

        return to_route('provider.applicant-counts.show', $scholarship)->with('status', 'Report received. External counts stay excluded from the public-count preview until administrator verification.');
    }
}
