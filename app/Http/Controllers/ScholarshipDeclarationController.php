<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarshipDeclaration;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\ScholarshipNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScholarshipDeclarationController extends Controller
{
    private function canReview(Request $request, ScholarshipDeclaration $declaration): bool
    {
        return $request->user()->role->value === 'provider' && $request->user()->providerProfile?->isApproved()
            && Application::where('student_id', $declaration->student_id)->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->exists();
    }

    public function index(Request $request): Response
    {
        $student = $request->user()->role->value === 'student';
        $query = ScholarshipDeclaration::query();
        if ($student) {
            $query->where('student_id', $request->user()->id);
        } else {
            abort_unless($request->user()->providerProfile?->isApproved(), 403);
            $query->whereIn('student_id', Application::whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->select('student_id'));
        }

        return Inertia::render('Declarations', ['declarations' => $query->latest('id')->paginate(20)->through(function ($item) use ($student, $request): array {
            return $item->only('id', 'name', 'provider_name', 'starts_on', 'ends_on', 'details') + ['evidence_url' => route('declarations.evidence', $item),
                'reviews' => DB::table('scholarship_declaration_reviews')->where('scholarship_declaration_id', $item->id)->when(! $student, fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->get(['status', 'reason'])];
        }), 'documents' => $student ? StudentDocument::where('student_id', $request->user()->id)->get(['id', 'label']) : []]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->value === 'student', 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'provider_name' => ['required', 'string', 'max:180'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'details' => ['nullable', 'string', 'max:2000'], 'student_document_id' => ['required', 'integer']]);
        $document = StudentDocument::where('student_id', $request->user()->id)->findOrFail($data['student_document_id']);
        abort_unless(Storage::disk('documents')->exists($document->path), 422, 'Upload available evidence first.');
        DB::transaction(function () use ($request, $data): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (ScholarshipDeclaration::where('student_id', $request->user()->id)->where('name', $data['name'])->where('provider_name', $data['provider_name'])->whereDate('starts_on', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['name' => 'This scholarship declaration is already recorded.']);
            }
            $declaration = new ScholarshipDeclaration;
            $declaration->forceFill($data + ['student_id' => $request->user()->id])->save();
        });

        return back()->with('status', 'Self-reported scholarship saved. Verification is specific to each provider. Evidence is shared with providers for whose scholarships you prepare an application.');
    }

    public function review(Request $request, ScholarshipDeclaration $declaration): RedirectResponse
    {
        abort_unless($this->canReview($request, $declaration), 403);
        $data = $request->validate(['status' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $declaration, $data): void {
            User::whereKey($declaration->student_id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'verified') {
                $file = StudentDocument::withTrashed()->findOrFail($declaration->student_document_id);
                $disk = Storage::disk('documents');
                if (! $disk->exists($file->path) || ! hash_equals($file->checksum, hash_file('sha256', $disk->path($file->path)))) {
                    throw ValidationException::withMessages(['status' => 'The evidence is unavailable or has changed. Verification cannot be recorded.']);
                }
            }
            $previous = DB::table('scholarship_declaration_reviews')->where('scholarship_declaration_id', $declaration->id)->where('provider_id', $request->user()->providerProfile->id)->first();
            if ($previous && $previous->status === $data['status'] && $previous->reason === trim($data['reason'])) {
                return;
            }
            DB::table('scholarship_declaration_reviews')->updateOrInsert(['scholarship_declaration_id' => $declaration->id, 'provider_id' => $request->user()->providerProfile->id],
                ['status' => $data['status'], 'reason' => trim($data['reason']), 'reviewed_by' => $request->user()->id, 'created_at' => $previous?->created_at ?? now(), 'updated_at' => now()]);
            $request->user()->activityLogs()->create(['action' => 'declaration.reviewed', 'subject_type' => ScholarshipDeclaration::class, 'subject_id' => $declaration->id, 'changes' => ['status' => $data['status'], 'reason' => $data['reason']], 'occurred_at' => now()]);
            Application::where('student_id', $declaration->student_id)->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->providerProfile->id))->each(function (Application $application) use ($declaration, $data): void {
                app(ScholarshipNotices::class)->send($application->student, $application->scholarship, 'Outside scholarship verification', $declaration->name.': '.$data['status'].'. Review your declaration for details.', $application);
            });
        });

        return back()->with('status', 'Provider verification saved.');
    }

    public function evidence(Request $request, ScholarshipDeclaration $declaration): StreamedResponse
    {
        abort_unless($request->user()->id === $declaration->student_id || $this->canReview($request, $declaration), 403);
        $file = StudentDocument::withTrashed()->findOrFail($declaration->student_document_id);
        $disk = Storage::disk('documents');
        abort_unless($disk->exists($file->path) && hash_equals($file->checksum, hash_file('sha256', $disk->path($file->path))), 404);

        return $disk->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
