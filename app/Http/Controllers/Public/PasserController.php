<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasserController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'year' => ['nullable', 'integer', 'between:2000,2100'], 'term' => ['nullable', 'integer', 'exists:academic_terms,id'], 'type' => ['nullable', 'in:new,renewal']]);
        $search = $filters['q'] ?? '';
        $year = $filters['year'] ?? null;
        $termId = isset($filters['term']) ? (int) $filters['term'] : null;
        $query = ScholarshipAward::query()->publiclyListed();
        $termIds = (clone $query)->pluck('academic_term_id')->merge(Renewal::whereIn('scholarship_award_id', (clone $query)->select('id'))->where('status', 'approved')->whereNotNull('reviewed_at')->pluck('academic_term_id'))->unique();

        return Inertia::render('public/Passers', [
            'filters' => ['q' => $search, 'year' => $year === null ? '' : (string) $year, 'term' => (string) ($termId ?? ''), 'type' => $filters['type'] ?? ''],
            'terms' => AcademicTerm::whereIn('id', $termIds)->orderByDesc('academic_year')->orderBy('semester')->get()->map(fn (AcademicTerm $term): array => ['id' => $term->id, 'label' => $term->academic_year]),
            'passers' => $query->forAcademicTerm($termId, $filters['type'] ?? '')->with('application.scholarship.provider', 'academicTerm', 'renewals.academicTerm')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('public_name', '%'.$search.'%')->orWhereHas('application.scholarship', fn ($query) => $query->whereLike('title', '%'.$search.'%')->orWhereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$search.'%')))))
                ->when($year !== null, fn ($query) => $query->where('published_at', '>=', CarbonImmutable::create((int) $year, 1, 1, 0, 0, 0, 'Asia/Manila')->utc())->where('published_at', '<', CarbonImmutable::create((int) $year + 1, 1, 1, 0, 0, 0, 'Asia/Manila')->utc()))
                ->orderByDesc('published_at')->orderByDesc('id')->paginate(12)->withQueryString()->through(fn (ScholarshipAward $award): array => [
                    'name' => $award->public_name, 'scholarship' => $award->application->scholarship->title,
                    'provider' => $award->application->scholarship->provider->organization_name, 'published_at' => $award->published_at->toIso8601String(),
                    'term' => $award->academicTerm->academic_year,
                    'renewal_terms' => $award->renewals->filter(fn (Renewal $renewal): bool => $renewal->status->value === 'approved' && $renewal->reviewed_at !== null)->map(fn (Renewal $renewal): string => $renewal->academicTerm->academic_year)->values(),
                ]),
        ]);
    }
}
