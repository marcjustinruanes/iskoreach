<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipAward;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasserController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'year' => ['nullable', 'integer', 'between:2000,2100']]);
        $search = $filters['q'] ?? '';
        $year = $filters['year'] ?? null;

        return Inertia::render('public/Passers', [
            'filters' => ['q' => $search, 'year' => $year === null ? '' : (string) $year],
            'passers' => ScholarshipAward::query()->publiclyListed()->with('application.scholarship.provider')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('public_name', '%'.$search.'%')->orWhereHas('application.scholarship', fn ($query) => $query->whereLike('title', '%'.$search.'%')->orWhereHas('provider', fn ($query) => $query->whereLike('organization_name', '%'.$search.'%')))))
                ->when($year !== null, fn ($query) => $query->where('published_at', '>=', CarbonImmutable::create((int) $year, 1, 1, 0, 0, 0, 'Asia/Manila')->utc())->where('published_at', '<', CarbonImmutable::create((int) $year + 1, 1, 1, 0, 0, 0, 'Asia/Manila')->utc()))
                ->orderByDesc('published_at')->orderByDesc('id')->paginate(12)->withQueryString()->through(fn (ScholarshipAward $award): array => [
                    'name' => $award->public_name, 'scholarship' => $award->application->scholarship->title,
                    'provider' => $award->application->scholarship->provider->organization_name, 'published_at' => $award->published_at->toIso8601String(),
                ]),
        ]);
    }
}
