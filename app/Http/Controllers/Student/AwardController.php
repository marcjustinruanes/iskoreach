<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateAwardConsentRequest;
use App\Models\ScholarshipAward;
use App\Services\AwardPublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AwardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('student/Awards', [
            'awards' => ScholarshipAward::query()->whereHas('application', fn ($query) => $query->where('student_id', $request->user()->id))
                ->with(['application.scholarship.provider', 'application.student:id,role', 'academicTerm'])->latest('id')->paginate(10)->through(fn (ScholarshipAward $award): array => [
                    'id' => $award->id, 'scholarship' => $award->application->scholarship->title,
                    'provider' => $award->application->scholarship->provider->organization_name,
                    'status' => $award->status->value, 'active' => $award->isCurrentlyActive(), 'consented' => $award->public_listing_consented_at !== null,
                    'published' => $award->canBePubliclyListed() && $award->published_at !== null && $award->public_name !== null,
                    'version' => $award->publication_version, 'consent_url' => route('student.awards.consent', $award),
                ]),
        ]);
    }

    public function update(UpdateAwardConsentRequest $request, ScholarshipAward $award, AwardPublication $publication): RedirectResponse
    {
        $publication->update($request->user(), $award, $request->validated());

        return back()->with('status', $request->boolean('consent') ? 'Consent recorded. Your award awaits publication approval.' : 'Consent withdrawn. Your award is hidden from the public passers list.');
    }
}
