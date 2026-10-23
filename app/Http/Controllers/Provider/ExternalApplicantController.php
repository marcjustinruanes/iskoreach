<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreExternalApplicantRequest;
use App\Models\Scholarship;
use App\Services\ExternalApplicants;
use Illuminate\Http\RedirectResponse;

class ExternalApplicantController extends Controller
{
    public function store(StoreExternalApplicantRequest $request, Scholarship $scholarship, ExternalApplicants $applicants): RedirectResponse
    {
        $application = $applicants->store($request->user(), $scholarship, $request->validated());

        return to_route('provider.applications.show', $application)->with('status', 'External applicant recorded. No login account was created.');
    }
}
