<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAcademicTermsRequest;
use App\Http\Requests\Admin\SaveAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Services\AcademicTermManagement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTermController extends Controller
{
    public function index(ListAcademicTermsRequest $request, AcademicTermManagement $management): Response
    {
        $filters = ['q' => $request->validated('q') ?? '', 'usage' => $request->validated('usage') ?? '', 'sort' => $request->validated('sort') ?? 'year_desc'];
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
        }), 'storeUrl' => route('admin.academic-terms.store')]);
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
