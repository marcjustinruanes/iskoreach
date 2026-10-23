<?php

namespace App\Http\Controllers\Public;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseScholarshipsRequest;
use App\Models\AcademicTerm;
use App\Models\Scholarship;
use App\Services\ScholarshipAcademicPriority;
use App\Services\ScholarshipCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScholarshipController extends Controller
{
    public function apply(string $slug, ScholarshipCatalog $catalog): RedirectResponse
    {
        $item = $catalog->visible()->where('slug', $slug)->firstOrFail();

        return to_route('scholarships.show', $item->slug);
    }

    public function index(BrowseScholarshipsRequest $request, ScholarshipCatalog $catalog): Response
    {
        $filters = array_fill_keys(['q', 'location', 'type', 'availability', 'existing', 'term', 'currency', 'amount_min', 'amount_max', 'opens_from', 'opens_to', 'deadline_from', 'deadline_to'], '');
        foreach ($filters as $key => $value) {
            $filters[$key] = (string) ($request->validated($key) ?? '');
        }

        $workspace = $request->routeIs('student.*');

        return Inertia::render('public/Scholarships', ['filters' => $filters,
            'studentWorkspace' => $workspace,
            'browseUrl' => route($workspace ? 'student.scholarships.index' : 'scholarships.index'),
            'scholarships' => $catalog->browse($filters)->with(['provider:id,organization_name', 'locations', 'academicTerm'])->orderByDesc('published_at')->orderByDesc('id')->paginate(6)->withQueryString()->through(fn ($item): array => $this->scholarshipData($catalog, $item, $workspace)),
            'types' => $catalog->visible()->where('scholarship_type', '!=', '')->select('scholarship_type')->distinct()->orderBy('scholarship_type')->pluck('scholarship_type'),
            'terms' => AcademicTerm::query()->whereIn('id', $catalog->visible()->select('academic_term_id'))->orderByDesc('academic_year')->orderBy('semester')->get()->map(fn ($term): array => ['id' => $term->id, 'label' => $term->academic_year])]);
    }

    public function show(Request $request, string $slug, ScholarshipCatalog $catalog): Response
    {
        $item = $catalog->visible()->where('slug', $slug)->with(['provider:id,organization_name', 'locations', 'requirements', 'academicTerm'])->firstOrFail();

        $workspace = $request->routeIs('student.*');

        return Inertia::render('public/ScholarshipDetails', ['studentWorkspace' => $workspace,
            'browseUrl' => route($workspace ? 'student.scholarships.index' : 'scholarships.index'),
            'scholarship' => [...$this->scholarshipData($catalog, $item, $workspace), 'description' => $item->description,
                'academic_eligible' => $request->user()?->role === UserRole::Student ? app(ScholarshipAcademicPriority::class)->eligible($item, $request->user()->studentProfile?->only('education_level', 'course', 'year_level', 'graduation_year') ?? []) : null, 'eligibility_rules' => $item->eligibility_rules, 'requirements' => $item->requirements->map(fn ($requirement): array => $requirement->only('id', 'name', 'description', 'required', 'requirement_stage', 'allowed_mime_types', 'max_size_kb'))]]);
    }

    /** @return array<string, mixed> */
    private function scholarshipData(ScholarshipCatalog $catalog, Scholarship $item, bool $workspace): array
    {
        $data = $catalog->data($item);
        if ($workspace) {
            $data['show_url'] = route('student.scholarships.show', $item->slug);
        }

        return $data;
    }
}
