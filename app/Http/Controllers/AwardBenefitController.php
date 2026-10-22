<?php

namespace App\Http\Controllers;

use App\Http\Requests\AwardBenefitRequest;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use App\Services\AwardBenefits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AwardBenefitController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $provider = $user->role->value === 'provider';
        abort_if($provider && ! $user->providerProfile?->isApproved(), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:active,suspended,completed,revoked'], 'q' => ['nullable', 'string', 'max:120']]);
        $query = ScholarshipAward::whereHas('application', fn ($query) => $provider ? $query->whereHas('scholarship.provider', fn ($query) => $query->where('user_id', $user->id)) : $query->where('student_id', $user->id));

        return Inertia::render('benefits/Index', ['awards' => $query->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))->when($filters['q'] ?? null, fn ($query, $value) => $query->whereHas('application.scholarship', fn ($query) => $query->whereLike('title', '%'.$value.'%')))->with('application.scholarship.provider', 'academicTerm')->latest('id')->paginate(10)->withQueryString()->through($this->awardData(...)), 'filters' => ['status' => $filters['status'] ?? '', 'q' => $filters['q'] ?? '']]);
    }

    public function create(Request $request, Application $application, AwardBenefits $workflow): Response
    {
        Gate::authorize('review', $application);
        abort_unless($application->status->value === 'accepted' && ! $application->scholarship->trashed(), 403);
        $existing = ScholarshipAward::where('application_id', $application->id)->first();

        return Inertia::render('benefits/Create', ['application' => ['id' => $application->id, 'version' => $application->workflow_version, 'scholarship' => $application->scholarship->title,
            'student' => $application->submission_snapshot['student']['name'] ?? 'Submitted account #'.$application->student_id,
            'accepts_existing_scholars' => $application->scholarship->accepts_existing_scholars, 'context' => $workflow->context($application), 'existing_url' => $existing ? route('benefits.show', $existing) : null],
            'terms' => AcademicTerm::orderByDesc('starts_on')->get()->map($this->termData(...))]);
    }

    public function store(AwardBenefitRequest $request, Application $application, AwardBenefits $workflow): RedirectResponse
    {
        abort_unless($request->validated('action') === 'award_create', 422);
        $award = $workflow->create($request->user(), $application, $request->validated());

        return to_route('benefits.show', $award)->with('status', 'Award recorded. Public listing still requires student consent and publication approval.');
    }

    public function show(Request $request, ScholarshipAward $award): Response
    {
        Gate::authorize('view', $award);
        $provider = $request->user()->role->value === 'provider';
        $ids = Renewal::where('scholarship_award_id', $award->id)->where('status', 'approved')->whereNotNull('reviewed_at')->pluck('academic_term_id')->push($award->academic_term_id)->unique();

        return Inertia::render('benefits/Show', ['award' => $this->awardData($award) + ['history' => $award->history ?? [], 'editable' => ! $award->application->trashed() && ! $award->application->scholarship->trashed() && $award->application->status->value === 'accepted' && $award->application->submitted_at !== null],
            'releases' => BenefitRelease::where('scholarship_award_id', $award->id)->with('academicTerm')->latest('id')->paginate(10)->through(fn ($item) => ['id' => $item->id, 'version' => $item->workflow_version, 'amount' => $item->amount, 'currency' => $item->currency, 'status' => $item->status->value,
                'term' => $this->termData($item->academicTerm)['label'], 'scheduled_at' => $item->scheduled_at?->toIso8601String(), 'released_at' => $item->released_at?->toIso8601String(), 'notes' => $item->notes, 'history' => $item->history ?? []]),
            'terms' => $provider ? AcademicTerm::whereIn('id', $ids)->get()->map($this->termData(...)) : []]);
    }

    public function update(AwardBenefitRequest $request, ScholarshipAward $award, AwardBenefits $workflow): RedirectResponse
    {
        abort_unless(in_array($request->validated('action'), ['award_complete', 'award_revoke', 'award_suspend', 'award_resume'], true), 422);
        $workflow->transition($request->user(), $award, $request->validated());

        return to_route('benefits.show', $award)->with('status', 'Award status updated. Any public listing has been removed.');
    }

    public function storeRelease(AwardBenefitRequest $request, ScholarshipAward $award, AwardBenefits $workflow): RedirectResponse
    {
        abort_unless($request->validated('action') === 'release_create', 422);
        $workflow->createRelease($request->user(), $award, $request->validated());

        return to_route('benefits.show', $award)->with('status', 'Benefit record saved. No money was transferred by IskoReach.');
    }

    public function updateRelease(AwardBenefitRequest $request, BenefitRelease $release, AwardBenefits $workflow): RedirectResponse
    {
        abort_unless(in_array($request->validated('action'), ['release_process', 'release_delay', 'release_fail', 'release_release'], true), 422);
        $workflow->releaseTransition($request->user(), $release, $request->validated());

        return to_route('benefits.show', $release->scholarship_award_id)->with('status', 'Benefit status recorded.');
    }

    private function awardData(ScholarshipAward $award): array
    {
        return ['id' => $award->id, 'version' => $award->workflow_version, 'application_id' => $award->application_id, 'scholarship' => $award->application->scholarship->title,
            'provider' => $award->application->scholarship->provider->organization_name, 'student_reference' => 'Application #'.$award->application_id,
            'status' => $award->status->value, 'active' => $award->isCurrentlyActive(), 'starts_on' => $award->starts_on->toDateString(), 'ends_on' => $award->ends_on?->toDateString(),
            'term' => $this->termData($award->academicTerm)['label'], 'url' => route('benefits.show', $award)];
    }

    private function termData(AcademicTerm $term): array
    {
        return ['id' => $term->id, 'label' => $term->academic_year.' · '.$term->semester];
    }
}
