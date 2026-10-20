<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListScholarshipsRequest;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\ApplicantCounts;
use App\Services\ScholarshipLocationSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScholarshipController extends Controller
{
    public function index(ListScholarshipsRequest $request): Response
    {
        $filters = array_fill_keys(['q', 'status', 'provider', 'category', 'location', 'records', 'opens_from', 'opens_to', 'deadline_from', 'deadline_to'], '');
        foreach ($filters as $key => $value) {
            $filters[$key] = trim((string) ($request->validated($key) ?? ''));
        }
        $query = Scholarship::withTrashed()->with(['provider:id,organization_name', 'locations'])
            ->when($filters['records'] === 'current', fn ($query) => $query->whereNull('deleted_at'))
            ->when($filters['records'] === 'retained', fn ($query) => $query->whereNotNull('deleted_at'))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%')
                ->orWhereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$filters['q'].'%'))))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['provider'] !== '', fn ($query) => $query->where('provider_id', $filters['provider']))
            ->when($filters['category'] !== '', fn ($query) => $query->where('scholarship_type', $filters['category']));
        app(ScholarshipLocationSearch::class)->apply($query, $filters['location']);
        foreach (['opens' => 'opens_at', 'deadline' => 'deadline_at'] as $prefix => $column) {
            if ($filters[$prefix.'_from'] !== '') {
                $query->where($column, '>=', CarbonImmutable::parse($filters[$prefix.'_from'], 'Asia/Manila')->startOfDay()->utc());
            }
            if ($filters[$prefix.'_to'] !== '') {
                $query->where($column, '<', CarbonImmutable::parse($filters[$prefix.'_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
            }
        }

        return Inertia::render('admin/Scholarships', [
            'filters' => $filters,
            'scholarships' => $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()
                ->through(fn (Scholarship $scholarship): array => $this->scholarshipData($scholarship)),
            'providers' => ProviderProfile::query()->whereHas('scholarships', fn ($query) => $query->withTrashed())->orderBy('organization_name')->get(['id', 'organization_name'])
                ->map(fn (ProviderProfile $provider): array => ['id' => $provider->id, 'name' => $provider->organization_name ?: 'Organization #'.$provider->id]),
            'categories' => Scholarship::withTrashed()->select('scholarship_type')->distinct()->orderBy('scholarship_type')->pluck('scholarship_type'),
        ]);
    }

    public function show(Scholarship $scholarship): Response
    {
        Gate::authorize('view', $scholarship);
        $scholarship->load(['provider:id,organization_name,verification_status', 'locations', 'requirements', 'academicTerm']);

        return Inertia::render('admin/ScholarshipDetails', [
            'scholarship' => [
                ...$this->scholarshipData($scholarship), 'description' => $scholarship->description,
                'benefit_amount' => $scholarship->benefit_amount, 'currency' => $scholarship->currency,
                'eligibility_rules' => $scholarship->eligibility_rules, 'published_at' => $scholarship->published_at?->toIso8601String(),
                'provider_status' => $scholarship->provider->verification_status->value,
                'provider_url' => route('admin.providers.show', $scholarship->provider),
                'academic_term' => $scholarship->academicTerm ? $scholarship->academicTerm->academic_year.' · '.$scholarship->academicTerm->semester : null,
                'requirements' => $scholarship->requirements->map(fn ($requirement): array => $requirement->only('id', 'name', 'description', 'required', 'allowed_mime_types', 'max_size_kb', 'requirement_stage')),
            ],
        ]);
    }

    private function scholarshipData(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id, 'title' => $scholarship->title, 'provider' => $scholarship->provider->organization_name ?: 'Organization #'.$scholarship->provider_id,
            'scholarship_type' => $scholarship->scholarship_type, 'status' => $scholarship->status->value,
            'locations' => $scholarship->locations->map(fn ($location): array => $location->only('id', 'scope', 'region_code', 'city_code', 'region_name', 'city_name')),
            'opens_at' => $scholarship->opens_at?->toIso8601String(), 'deadline_at' => $scholarship->deadline_at?->toIso8601String(),
            'accepts_existing_scholars' => $scholarship->accepts_existing_scholars,
            'updated_at' => $scholarship->updated_at?->toIso8601String(), 'deleted_at' => $scholarship->deleted_at?->toIso8601String(),
            'applicant_count' => app(ApplicantCounts::class)->summary($scholarship)['public_count'],
            'counts_url' => route('admin.applicant-counts.show', $scholarship), 'show_url' => route('admin.scholarships.show', $scholarship),
        ];
    }
}
