<?php

namespace App\Services;

use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ScholarshipCatalog
{
    public function visible(): Builder
    {
        return Scholarship::query()->whereIn('status', ['published', 'closed'])->whereNotNull('published_at')
            ->whereHas('provider', fn ($query) => $query->where('verification_status', 'approved')->whereHas('user', fn ($query) => $query->where('role', 'provider')));
    }

    public function browse(array $filters): Builder
    {
        $query = $this->visible();
        foreach (['type' => 'scholarship_type', 'term' => 'academic_term_id', 'currency' => 'currency'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        $query->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%')->orWhereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$filters['q'].'%'))))
            ->when($filters['existing'] !== '', fn ($query) => $query->where('accepts_existing_scholars', $filters['existing'] === 'yes'));
        app(ScholarshipLocationSearch::class)->apply($query, $filters['location']);
        foreach (['amount_min' => '>=', 'amount_max' => '<='] as $field => $operator) {
            if ($filters[$field] !== '') {
                $query->where('benefit_amount', $operator, $filters[$field])->where('currency', $filters['currency'] ?: 'PHP');
            }
        }
        if ($filters['availability'] === 'open') {
            $query->where('status', 'published')->where('opens_at', '<=', now())->where('deadline_at', '>', now());
        } elseif ($filters['availability'] === 'upcoming') {
            $query->where('status', 'published')->where('opens_at', '>', now())->where('deadline_at', '>', now());
        } elseif ($filters['availability'] === 'closed') {
            $query->where(fn ($query) => $query->where('status', 'closed')->orWhere('deadline_at', '<=', now()));
        }
        foreach (['opens' => 'opens_at', 'deadline' => 'deadline_at'] as $prefix => $column) {
            if ($filters[$prefix.'_from'] !== '') {
                $query->where($column, '>=', CarbonImmutable::parse($filters[$prefix.'_from'], 'Asia/Manila')->startOfDay()->utc());
            }
            if ($filters[$prefix.'_to'] !== '') {
                $query->where($column, '<', CarbonImmutable::parse($filters[$prefix.'_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
            }
        }

        return $query;
    }

    public function data(Scholarship $item): array
    {
        $summary = app(ApplicantCounts::class)->summary($item);

        return ['id' => $item->id, 'title' => $item->title, 'provider' => $item->provider->organization_name,
            'type' => $item->scholarship_type, 'amount' => $item->benefit_amount, 'currency' => $item->currency,
            'opens_at' => $item->opens_at?->toIso8601String(), 'deadline_at' => $item->deadline_at?->toIso8601String(),
            'availability' => $item->status->value === 'closed' || $item->deadline_at?->lte(now()) ? 'Closed' : (! $item->opens_at || ! $item->deadline_at ? 'Dates not recorded' : ($item->opens_at->isFuture() ? 'Opens later' : 'Open now')),
            'accepts_existing_scholars' => $item->accepts_existing_scholars,
            'locations' => $item->locations->map(fn ($location): array => $location->only('id', 'scope', 'region_code', 'city_code', 'region_name', 'city_name')),
            'academic_term' => $item->academicTerm ? $item->academicTerm->academic_year.' · '.$item->academicTerm->semester : null,
            'internal_count' => $summary['internal_count'], 'external_count' => $summary['verified_external_count'], 'applicant_count' => $summary['public_count'],
            'verified_source_as_of' => $summary['verified_source_as_of'], 'show_url' => route('scholarships.show', $item->slug)];
    }
}
