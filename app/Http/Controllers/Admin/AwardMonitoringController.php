<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AwardStatus;
use App\Enums\BenefitReleaseStatus;
use App\Enums\RenewalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAwardMonitoringRequest;
use App\Http\Requests\Admin\UpdateAwardPublicationRequest;
use App\Models\AcademicTerm;
use App\Models\BenefitRelease;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use App\Services\AwardPublication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AwardMonitoringController extends Controller
{
    public function awards(ListAwardMonitoringRequest $request): Response
    {
        return $this->listing($request, 'awards');
    }

    public function renewals(ListAwardMonitoringRequest $request): Response
    {
        return $this->listing($request, 'renewals');
    }

    public function releases(ListAwardMonitoringRequest $request): Response
    {
        return $this->listing($request, 'releases');
    }

    public function publish(UpdateAwardPublicationRequest $request, ScholarshipAward $award, AwardPublication $publication): RedirectResponse
    {
        $publication->update($request->user(), $award, $request->validated());

        return back()->with('status', $request->validated('action') === 'publish' ? 'Award approved for the public passers list.' : 'Award removed from the public passers list.');
    }

    private function listing(ListAwardMonitoringRequest $request, string $section): Response
    {
        $filters = array_fill_keys(['q', 'status', 'provider', 'term', 'award', 'from', 'to', 'activity', 'consent'], '');
        foreach ($filters as $key => $value) {
            $filters[$key] = trim((string) ($request->validated($key) ?? ''));
        }
        $awards = $section === 'awards';
        $query = match ($section) {
            'renewals' => Renewal::query(), 'releases' => BenefitRelease::query(), default => ScholarshipAward::query()
        };
        $prefix = $awards ? '' : 'award.';
        $query->with([$prefix.'application.scholarship.provider:id,organization_name,verification_status', $prefix.'application.student:id,role', 'academicTerm']);
        $application = $prefix.'application';
        $query->when($filters['q'] !== '', fn (Builder $query) => $query->whereHas($application.'.scholarship', fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereLike('title', '%'.$filters['q'].'%')->orWhereHas('provider', fn (Builder $query) => $query->whereLike('organization_name', '%'.$filters['q'].'%')))));
        $query->when($filters['provider'] !== '', fn (Builder $query) => $query->whereHas($application.'.scholarship', fn (Builder $query) => $query->where('provider_id', $filters['provider'])))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['term'] !== '', fn (Builder $query) => $query->where('academic_term_id', $filters['term']))
            ->when($filters['award'] !== '', fn (Builder $query) => $query->where($awards ? 'id' : 'scholarship_award_id', $filters['award']));
        if ($awards) {
            if ($filters['activity'] === 'active') {
                $query->active();
            } elseif ($filters['activity'] === 'inactive') {
                $query->whereNotIn('id', ScholarshipAward::query()->active()->select('id'));
            }
            if ($filters['consent'] === 'given') {
                $query->whereNotNull('public_listing_consented_at');
            } elseif ($filters['consent'] === 'missing') {
                $query->whereNull('public_listing_consented_at');
            }
        }
        $dateColumn = match ($section) {
            'renewals' => 'deadline_at', 'releases' => 'scheduled_at', default => 'starts_on'
        };
        if ($filters['from'] !== '') {
            $query->where($dateColumn, '>=', $awards ? $filters['from'] : CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] !== '') {
            $query->where($dateColumn, '<', $awards ? CarbonImmutable::parse($filters['to'])->addDay()->toDateString() : CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
        $enum = match ($section) {
            'renewals' => RenewalStatus::class, 'releases' => BenefitReleaseStatus::class, default => AwardStatus::class
        };

        return Inertia::render('admin/AwardMonitoring', [
            'section' => $section, 'filters' => $filters,
            'records' => $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($record): array => $this->recordData($record)),
            'statuses' => array_map(fn ($status): string => $status->value, $enum::cases()),
            'providers' => ProviderProfile::query()->orderBy('organization_name')->get(['id', 'organization_name'])->map(fn ($provider): array => ['id' => $provider->id, 'name' => $provider->organization_name ?: 'Organization #'.$provider->id]),
            'terms' => AcademicTerm::query()->orderByDesc('academic_year')->orderBy('semester')->get()->map(fn ($term): array => ['id' => $term->id, 'name' => $term->academic_year.' · '.$term->semester]),
        ]);
    }

    private function recordData(ScholarshipAward|Renewal|BenefitRelease $record): array
    {
        $award = $record instanceof ScholarshipAward ? $record : $record->award;
        $application = $award->application;
        $scholarship = $application->scholarship;
        $data = [
            'id' => $record->id, 'award_id' => $award->id, 'application_reference' => 'APP-'.$application->id,
            'scholarship' => $scholarship->title, 'scholarship_url' => route('admin.scholarships.show', $scholarship),
            'provider' => $scholarship->provider->organization_name ?: 'Organization #'.$scholarship->provider_id,
            'term' => $record->academicTerm->academic_year.' · '.$record->academicTerm->semester,
            'status' => $record->status->value, 'active_award' => $award->isCurrentlyActive(),
            'retained' => $application->trashed() || $scholarship->trashed(), 'updated_at' => $record->updated_at->toIso8601String(),
        ];
        if ($record instanceof ScholarshipAward) {
            return [...$data, 'starts_on' => $record->starts_on->toDateString(), 'ends_on' => $record->ends_on?->toDateString(),
                'consented_at' => $record->public_listing_consented_at?->toIso8601String(), 'published_at' => $record->published_at?->toIso8601String(),
                'publicly_visible' => $record->canBePubliclyListed() && $record->published_at !== null && $record->public_name !== null,
                'can_publish' => $record->canBePubliclyListed(), 'publication_version' => $record->publication_version,
                'publication_url' => route('admin.awards.publication', $record)];
        }
        if ($record instanceof Renewal) {
            return [...$data, 'deadline_at' => $record->deadline_at?->toIso8601String(), 'submitted_at' => $record->submitted_at?->toIso8601String(),
                'reviewed_at' => $record->reviewed_at?->toIso8601String(),
                'overdue' => in_array($record->status, [RenewalStatus::Pending, RenewalStatus::Submitted], true) && (bool) $record->deadline_at?->isPast()];
        }

        return [...$data, 'amount' => $record->amount, 'currency' => $record->currency, 'scheduled_at' => $record->scheduled_at?->toIso8601String(),
            'released_at' => $record->released_at?->toIso8601String(), 'release_confirmed' => $record->status === BenefitReleaseStatus::Released && $record->released_at !== null];
    }
}
