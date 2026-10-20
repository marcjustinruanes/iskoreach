<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipAward;
use App\Services\ScholarshipCatalog;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ScholarshipCatalog $catalog): Response
    {
        return Inertia::render('Welcome', [
            'recentScholarships' => $catalog->visible()->with(['provider:id,organization_name', 'locations', 'academicTerm'])
                ->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get()->map($catalog->data(...)),
            'publicStats' => [
                'programs' => $catalog->visible()->count(),
                'open' => $catalog->visible()->where('status', 'published')->where('opens_at', '<=', now())->where('deadline_at', '>', now())->count(),
                'passers' => ScholarshipAward::query()->publiclyListed()->count(),
            ],
        ]);
    }
}
