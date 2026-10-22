<?php

namespace App\Http\Controllers;

use App\Http\Requests\RenewalMutationRequest;
use App\Models\AcademicTerm;
use App\Models\Renewal;
use App\Models\RenewalDocument;
use App\Models\ScholarshipAward;
use App\Models\StudentDocument;
use App\Services\PrivateDocuments;
use App\Services\RenewalWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RenewalController extends Controller
{
    public function index(Request $request, RenewalWorkflow $workflow): Response
    {
        $user = $request->user();
        $provider = $user->role->value === 'provider';
        abort_if($provider && $user->providerProfile?->verification_status->value !== 'approved', 403);
        $filters = $request->validate(['status' => ['nullable', 'in:pending,submitted,approved,rejected']]);
        $query = Renewal::query()->whereHas('award.application', fn ($query) => $provider
        ? $query->whereHas('scholarship.provider', fn ($query) => $query->where('user_id', $user->id))
        : $query->where('student_id', $user->id));
        $awards = $provider ? ScholarshipAward::active()->whereHas('application.scholarship.provider', fn ($query) => $query->where('user_id', $user->id))->with('application.scholarship')->get()->filter($workflow->eligible(...))->map(fn ($award) => ['id' => $award->id, 'label' => 'AW-'.$award->id.' · '.$award->application->scholarship->title])->values() : [];

        return Inertia::render('renewals/Index', ['renewals' => $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->with('award.application.scholarship', 'academicTerm')->latest('id')->paginate(10)->withQueryString()->through(fn ($item) => $this->summary($item)),
            'filters' => ['status' => $filters['status'] ?? ''], 'awards' => $awards, 'terms' => $provider ? AcademicTerm::whereDate('ends_on', '>=', now('Asia/Manila')->toDateString())->orderBy('starts_on')->get()->map(fn ($term) => ['id' => $term->id, 'label' => $term->academic_year.' · '.$term->semester]) : []]);
    }

    public function store(Request $request, RenewalWorkflow $workflow): RedirectResponse
    {
        abort_unless($request->user()->role->value === 'provider', 403);
        $data = $request->validate(['award_id' => ['required', 'integer'], 'academic_term_id' => ['required', 'integer'], 'deadline_at' => ['required', 'date_format:Y-m-d\\TH:i'], 'confirmed' => ['required', 'accepted']]);
        $renewal = $workflow->create($request->user(), $data);

        return to_route('renewals.show', $renewal)->with('status', 'Renewal request opened. The student has been notified.');
    }

    public function show(Request $request, Renewal $renewal, RenewalWorkflow $workflow, PrivateDocuments $documents): Response
    {
        Gate::authorize('view', $renewal);
        $student = $request->user()->role->value === 'student';
        $data = $this->summary($renewal);
        $data += ['version' => $renewal->workflow_version, 'eligible' => $workflow->eligible($renewal->award, true), 'history' => $renewal->history ?? [], 'notes' => $renewal->review_notes,
            'checklist_recorded' => $renewal->requirements_snapshot !== null, 'checklist' => $workflow->checklist($renewal)];
        if (! $student) {
            foreach ($data['checklist'] as &$row) {
                if ($renewal->submitted_at === null) {
                    $row['attachment'] = null;
                    $row['complete'] = false;
                }
            }
            unset($row);
        }

        return Inertia::render('renewals/Show', ['renewal' => $data, 'documents' => $student && $renewal->status->value === 'pending' ? StudentDocument::where('student_id', $request->user()->id)->latest()->get()->map($documents->vaultData(...)) : []]);
    }

    public function update(RenewalMutationRequest $request, Renewal $renewal, RenewalWorkflow $workflow): RedirectResponse
    {
        $workflow->mutate($request->user(), $renewal, $request->validated());

        return to_route('renewals.show', $renewal)->with('status', 'Renewal updated.');
    }

    public function download(Request $request, RenewalDocument $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->renewal);
        $student = $request->user()->role->value === 'student';
        abort_unless($student || ($attachment->submitted_at !== null && $attachment->renewal->submitted_at !== null && $attachment->removed_at === null), 403);
        $file = $attachment->document;
        $disk = Storage::disk('documents');
        abort_unless($disk->exists($file->path) && $disk->size($file->path) === $file->size_bytes && hash_equals($file->checksum, hash_file('sha256', $disk->path($file->path))), 404);

        return $disk->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function summary(Renewal $renewal): array
    {
        return ['id' => $renewal->id, 'scholarship' => $renewal->award->application->scholarship->title, 'award_id' => $renewal->scholarship_award_id,
            'term' => $renewal->academicTerm->academic_year.' · '.$renewal->academicTerm->semester, 'status' => $renewal->status->value,
            'deadline_at' => $renewal->deadline_at?->toIso8601String(), 'submitted_at' => $renewal->submitted_at?->toIso8601String(),
            'url' => route('renewals.show', $renewal)];
    }
}
