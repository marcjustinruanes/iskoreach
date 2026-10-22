<?php

namespace App\Http\Controllers\Provider;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\SaveScholarshipRequest;
use App\Http\Requests\Provider\ScholarshipLifecycleRequest;
use App\Models\AcademicTerm;
use App\Models\Scholarship;
use App\Services\ScholarshipCatalog;
use App\Services\ScholarshipManagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScholarshipController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('create', Scholarship::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['draft', 'published', 'closed', 'archived'])], 'records' => ['nullable', Rule::in(['current', 'retained'])], 'category' => ['nullable', 'string', 'max:100'], 'availability' => ['nullable', Rule::in(['upcoming', 'open', 'ended'])], 'sort' => ['nullable', Rule::in(['updated', 'created', 'deadline', 'applicants'])]]);
        $filters = array_replace(['q' => '', 'status' => '', 'records' => 'current', 'category' => '', 'availability' => '', 'sort' => 'updated'], array_filter($filters, fn ($value) => $value !== null));
        $query = Scholarship::withTrashed()->where('provider_id', $request->user()->providerProfile->id)
            ->when($filters['records'] === 'retained', fn ($query) => $query->whereNotNull('deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->when($filters['q'] !== '', fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['category'] !== '', fn ($query) => $query->where('scholarship_type', $filters['category']))
            ->when($filters['availability'] === 'upcoming', fn ($query) => $query->where('status', 'published')->where('opens_at', '>', now()))
            ->when($filters['availability'] === 'open', fn ($query) => $query->where('status', 'published')->where('opens_at', '<=', now())->where('deadline_at', '>', now()))
            ->when($filters['availability'] === 'ended', fn ($query) => $query->where(fn ($query) => $query->where('status', 'closed')->orWhere('deadline_at', '<=', now())))
            ->withCount(['applications as applicant_count' => fn ($query) => $query->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())->whereHas('student', fn ($query) => $query->where('role', 'student')),
                'applications as pending_count' => fn ($query) => $query->whereNotNull('submitted_at')->whereIn('status', ['submitted', 'under_review'])]);
        match ($filters['sort']) {
            'deadline' => $query->orderByRaw('deadline_at IS NULL')->orderBy('deadline_at'),
            'created' => $query->latest('created_at'),
            'applicants' => $query->orderByDesc('applicant_count'),
            default => $query->latest('updated_at'),
        };

        return Inertia::render('provider/Scholarships', ['filters' => $filters, 'scholarships' => $query->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($item): array => [
            'id' => $item->id, 'title' => $item->title, 'status' => $item->status->value, 'version' => $item->management_version,
            'category' => $item->scholarship_type, 'opens_at' => $item->opens_at?->toIso8601String(), 'deadline_at' => $item->deadline_at?->toIso8601String(), 'applicant_count' => $item->applicant_count, 'pending_count' => $item->pending_count,
            'preview_url' => $item->trashed() ? null : route('provider.scholarships.preview', $item), 'retained' => $item->trashed(), 'updated_at' => $item->updated_at->toIso8601String(),
            'edit_url' => $item->trashed() ? null : route('provider.scholarships.edit', $item),
            'lifecycle_url' => $item->trashed() ? null : route('provider.scholarships.lifecycle', $item),
            'public_url' => $item->published_at && in_array($item->status->value, ['published', 'closed']) && ! $item->trashed() ? route('scholarships.show', $item->slug) : null,
        ])]);
    }

    public function preview(Scholarship $scholarship, ScholarshipCatalog $catalog): Response
    {
        Gate::authorize('manage', $scholarship);
        $scholarship->load('provider', 'locations', 'requirements', 'academicTerm');

        return Inertia::render('public/ScholarshipDetails', ['scholarship' => [
            ...$catalog->data($scholarship), 'description' => $scholarship->description,
            'eligibility_rules' => $scholarship->eligibility_rules,
            'requirements' => $scholarship->requirements->map(fn ($requirement): array => $requirement->only('id', 'name', 'description', 'required', 'requirement_stage', 'allowed_mime_types', 'max_size_kb')),
        ], 'preview' => true, 'browseUrl' => route('provider.scholarships.index')]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Scholarship::class);

        return Inertia::render('provider/ScholarshipForm', ['scholarship' => null, 'formData' => null, 'locked' => false, 'terms' => $this->terms()]);
    }

    public function store(SaveScholarshipRequest $request, ScholarshipManagement $management): RedirectResponse
    {
        $item = $management->save($request->user(), $request->validated());

        return to_route('provider.scholarships.edit', $item)->with('status', 'Scholarship draft created. Publish it when its information is ready.');
    }

    public function edit(Scholarship $scholarship, ScholarshipManagement $management): Response
    {
        Gate::authorize('manage', $scholarship);

        return Inertia::render('provider/ScholarshipForm', ['scholarship' => ['id' => $scholarship->id, 'status' => $scholarship->status->value, 'lifecycle_url' => route('provider.scholarships.lifecycle', $scholarship), 'preview_url' => route('provider.scholarships.preview', $scholarship)],
            'formData' => $management->formData($scholarship), 'locked' => $scholarship->status->value === 'published' || $scholarship->applications()->whereNotNull('submitted_at')->exists(), 'terms' => $this->terms()]);
    }

    public function update(SaveScholarshipRequest $request, Scholarship $scholarship, ScholarshipManagement $management): RedirectResponse
    {
        $management->save($request->user(), $request->validated(), $scholarship);

        return back()->with('status', 'Scholarship details saved.');
    }

    public function lifecycle(ScholarshipLifecycleRequest $request, Scholarship $scholarship, ScholarshipManagement $management): RedirectResponse
    {
        $management->transition($request->user(), $scholarship, $request->validated());

        return to_route('provider.scholarships.index')->with('status', 'Scholarship lifecycle change saved. History has been retained.');
    }

    private function terms(): array
    {
        return AcademicTerm::query()->orderByDesc('academic_year')->orderBy('semester')->get()->map(fn ($term): array => ['id' => $term->id, 'label' => $term->academic_year.' · '.$term->semester])->all();
    }
}
