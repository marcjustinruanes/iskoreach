<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAcademicTermsRequest;
use App\Http\Requests\Admin\SaveAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use App\Services\AcademicTermManagement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTermController extends Controller
{
    public function index(ListAcademicTermsRequest $request, AcademicTermManagement $management): Response
    {
        $filters = ['q' => $request->validated('q') ?? '', 'usage' => $request->validated('usage') ?? '', 'sort' => $request->validated('sort') ?? 'year_desc'];
        $selectedTerm = $request->validated('term') ? AcademicTerm::findOrFail($request->validated('term')) : null;
        $grants = $selectedTerm ? ScholarshipAward::approvedProvider()->where('academic_term_id', $selectedTerm->id)
            ->with('application.student', 'application.scholarship.provider')->latest('id')->paginate(10, ['*'], 'grants_page')->withQueryString()->through(fn (ScholarshipAward $award): array => [
                'id' => $award->id, 'student' => $award->application->submission_snapshot['student']['name'] ?? $award->application->student?->name,
                'scholarship' => $award->application->scholarship->title, 'provider' => $award->application->scholarship->provider->organization_name,
                'status' => $award->status->value, 'published' => $award->canBePubliclyListed() && $award->published_at !== null && $award->public_name !== null,
            ]) : null;
        $renewals = $selectedTerm ? Renewal::where('academic_term_id', $selectedTerm->id)->whereHas('award', fn ($query) => $query->approvedProvider())
            ->with('award.application.student', 'award.application.scholarship.provider')->latest('id')->paginate(10, ['*'], 'renewals_page')->withQueryString()->through(fn (Renewal $renewal): array => [
                'id' => $renewal->id, 'student' => $renewal->award->application->submission_snapshot['student']['name'] ?? $renewal->award->application->student?->name,
                'scholarship' => $renewal->award->application->scholarship->title, 'provider' => $renewal->award->application->scholarship->provider->organization_name,
                'status' => $renewal->status->value, 'reviewed_at' => $renewal->reviewed_at?->toIso8601String(),
            ]) : null;
        $query = AcademicTerm::query()->withCount(['scholarships' => fn ($query) => $query->withTrashed(),
            'applications' => fn ($query) => $query->withTrashed(), 'awards', 'renewals', 'releases']);
        $query->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('academic_year', '%'.$filters['q'].'%')->orWhereLike('semester', '%'.$filters['q'].'%')));
        if ($filters['usage'] !== '') {
            $query->where(function ($query) use ($filters): void {
                foreach (AcademicTermManagement::REFERENCE_TABLES as $table) {
                    $method = $filters['usage'] === 'used' ? 'orWhereExists' : 'whereNotExists';
                    $query->$method(fn ($reference) => $reference->selectRaw('1')->from($table)->whereColumn($table.'.academic_term_id', 'academic_terms.id'));
                }
            });
        }
        if ($filters['sort'] === 'latest') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy('academic_year', $filters['sort'] === 'year_asc' ? 'asc' : 'desc')->orderBy('semester');
        }

        return Inertia::render('admin/AcademicTerms', ['filters' => $filters, 'terms' => $query->orderByDesc('id')->paginate(12)->withQueryString()->through(function (AcademicTerm $term) use ($management): array {
            $usage = ['scholarships' => $term->scholarships_count, 'applications' => $term->applications_count,
                'awards' => $term->awards_count, 'renewals' => $term->renewals_count, 'releases' => $term->releases_count];

            return [...$management->values($term), 'id' => $term->id, 'version' => $management->version($term), 'usage' => $usage,
                'editable' => array_sum($usage) === 0, 'update_url' => route('admin.academic-terms.update', $term)];
        }), 'termRecords' => ['selected' => $selectedTerm?->id, 'label' => $selectedTerm ? $selectedTerm->academic_year : null,
            'options' => AcademicTerm::orderByDesc('academic_year')->orderBy('semester')->get()->map(fn (AcademicTerm $term): array => ['id' => $term->id, 'label' => $term->academic_year]),
            'grants' => $grants, 'renewals' => $renewals], 'storeUrl' => route('admin.academic-terms.store')]);
    }

    public function store(SaveAcademicTermRequest $request, AcademicTermManagement $management): RedirectResponse
    {
        $management->save($request->user(), $request->validated());

        return to_route('admin.academic-terms.index')->with('status', 'Academic term created. It is now available in existing term selectors.');
    }

    public function update(SaveAcademicTermRequest $request, AcademicTerm $academicTerm, AcademicTermManagement $management): RedirectResponse
    {
        $changed = $management->save($request->user(), $request->validated(), $academicTerm);

        return back()->with('status', $changed ? 'Academic term updated.' : 'No changes were made.');
    }
}
