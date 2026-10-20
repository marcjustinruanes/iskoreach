<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListApplicantCountsRequest;
use App\Http\Requests\Admin\ReviewApplicantCountRequest;
use App\Models\ExternalApplicantCount;
use App\Models\Scholarship;
use App\Services\ApplicantCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantCountController extends Controller
{
    public function index(ListApplicantCountsRequest $request, ApplicantCounts $counts): Response
    {
        $search = trim($request->validated('q') ?? '');
        $pending = $request->validated('pending') ?? '';

        return Inertia::render('admin/ApplicantCounts', [
            'filters' => ['q' => $search, 'pending' => $pending],
            'scholarships' => Scholarship::withTrashed()->with('provider:id,organization_name')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('title', '%'.$search.'%')
                    ->orWhereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$search.'%'))))
                ->when($pending === '1', fn ($query) => $query->whereHas('externalCounts', fn ($query) => $query->where('status', 'pending')))
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()
                ->through(fn (Scholarship $scholarship): array => [
                    'id' => $scholarship->id, 'title' => $scholarship->title, 'provider' => $scholarship->provider->organization_name,
                    'summary' => $counts->summary($scholarship), 'show_url' => route('admin.applicant-counts.show', $scholarship),
                ]),
        ]);
    }

    public function show(Scholarship $scholarship, ApplicantCounts $counts): Response
    {
        Gate::authorize('view', $scholarship);

        return Inertia::render('admin/ApplicantCountDetails', [
            'scholarship' => ['id' => $scholarship->id, 'title' => $scholarship->title, 'provider' => $scholarship->provider->organization_name,
                'show_url' => route('admin.applicant-counts.show', $scholarship), 'record_url' => route('admin.scholarships.show', $scholarship)],
            'summary' => $counts->summary($scholarship),
            'reports' => $scholarship->externalCounts()->with(['reporter:id,name', 'reviewer:id,name'])->orderByDesc('id')->paginate(10)->withQueryString()
                ->through(fn (ExternalApplicantCount $report): array => [...$counts->reportData($report), 'review_url' => route('admin.applicant-counts.review', $report)]),
        ]);
    }

    public function update(ReviewApplicantCountRequest $request, ExternalApplicantCount $externalApplicantCount, ApplicantCounts $counts): RedirectResponse
    {
        $scholarship = $externalApplicantCount->scholarship;
        DB::transaction(function () use ($request, $externalApplicantCount, $counts, $scholarship): void {
            $lockedScholarship = Scholarship::withTrashed()->lockForUpdate()->findOrFail($scholarship->id);
            $report = ExternalApplicantCount::query()->lockForUpdate()->findOrFail($externalApplicantCount->id);
            $decision = $request->validated('decision');
            if (in_array($decision, ['approve', 'reject'], true) && $report->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This report has already been reviewed. Reload the history before continuing.']);
            }
            if (in_array($decision, ['correct', 'reverify'], true)) {
                $latest = $lockedScholarship->externalCounts()->where('status', 'approved')->orderByDesc('source_as_of')->orderByDesc('id')->first();
                if ($report->status !== 'approved' || $report->id !== $latest?->id || $report->id !== (int) $request->validated('expected_approved_id')) {
                    throw ValidationException::withMessages(['decision' => 'Reload the history and select the latest approved report before correcting or re-verifying.']);
                }
            }
            $snapshot = $counts->snapshot($lockedScholarship);
            if ($decision !== 'reject' && ! hash_equals(hash_hmac('sha256', $snapshot['fingerprint'], config('app.key')), $request->validated('expected_snapshot') ?? '')) {
                throw ValidationException::withMessages(['expected_snapshot' => 'Internal applicants changed. Reload the counts and recheck duplicates before approving.']);
            }
            if (in_array($decision, ['correct', 'reverify'], true)) {
                $original = $report;
                $report = new ExternalApplicantCount;
                $report->forceFill([
                    'scholarship_id' => $lockedScholarship->id, 'reported_count' => $original->reported_count,
                    'corrected_count' => $decision === 'correct' ? $request->validated('corrected_count') : $original->corrected_count,
                    'source_label' => $original->source_label, 'source_as_of' => $original->source_as_of,
                    'reported_by' => $request->user()->id, 'duplicates_excluded' => true,
                    'submission_token' => (string) Str::uuid(), 'correction_of_id' => $original->id,
                ]);
            }
            $report->status = $decision === 'reject' ? 'rejected' : 'approved';
            $report->reviewed_by = $request->user()->id;
            $report->reviewed_at = now();
            $report->reason = $request->validated('reason');
            $report->internal_fingerprint = $report->status === 'approved' ? $snapshot['fingerprint'] : null;
            $report->save();
            $request->user()->activityLogs()->create([
                'action' => 'external_count.'.$decision, 'subject_type' => ExternalApplicantCount::class, 'subject_id' => $report->id,
                'changes' => ['scholarship_id' => $lockedScholarship->id, 'reported_count' => $report->reported_count,
                    'corrected_count' => $report->corrected_count, 'reason' => $report->reason, 'correction_of_id' => $report->correction_of_id,
                    'duplicates_checked' => $decision !== 'reject', 'internal_count_at_review' => $snapshot['internal_count']],
                'occurred_at' => now(),
            ]);
        });

        return to_route('admin.applicant-counts.show', $scholarship)->with('status', 'External count decision saved. History has been retained.');
    }
}
