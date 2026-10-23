<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\User;
use App\Services\PrivateDocuments;
use App\Services\ScholarshipNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RequirementsController extends Controller
{
    public function index(Request $request, PrivateDocuments $documents): Response
    {
        return Inertia::render('student/Requirements', ['applications' => Application::where('student_id', $request->user()->id)->with('scholarship.requirements', 'academicTerm')->latest('updated_at')->paginate(8)->through(fn ($item): array => ['id' => $item->id, 'title' => $item->scholarship->title, 'semester' => $item->academicTerm?->academic_year, 'deadline' => $item->scholarship->deadline_at?->toIso8601String(), 'version' => $item->workflow_version, 'status' => $item->status->value, 'submitted' => $item->submitted_at !== null, 'url' => route('student.applications.show', $item), 'checklist' => $documents->checklist($item)]), 'upload_limit_kb' => $documents->uploadLimitKilobytes()]);
    }

    public function upload(Request $request, Application $application, ScholarshipRequirement $requirement, PrivateDocuments $documents): RedirectResponse
    {
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($requirement->scholarship_id === $application->scholarship_id && $requirement->requirement_stage === 'application', 404);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:'.implode(',', $requirement->allowed_mime_types ?? []), 'max:'.min($documents->uploadLimitKilobytes(), $requirement->max_size_kb ?? 0)], 'version' => ['required', 'integer'], 'confirmed' => ['required', 'accepted']]);
        $storedPath = null;
        try {
            DB::transaction(function () use ($request, $application, $requirement, $documents, $data, &$storedPath): void {
                ProviderProfile::lockForUpdate()->findOrFail($application->scholarship->provider_id);
                Scholarship::withTrashed()->lockForUpdate()->findOrFail($application->scholarship_id);
                User::lockForUpdate()->findOrFail($request->user()->id);
                $item = Application::lockForUpdate()->findOrFail($application->id);
                if ($item->workflow_version !== (int) $data['version'] || $item->scholarship->trashed()) {
                    throw ValidationException::withMessages(['file' => 'This application changed. Reload its requirements.']);
                }
                $previous = ApplicationDocument::where('application_id', $item->id)->where('scholarship_requirement_id', $requirement->id)->latest('version')->first();
                if ($item->submitted_at && (! $previous || $previous->review_status !== 'rejected' || $previous->removed_at || ! in_array($item->status->value, ['submitted', 'under_review', 'waitlisted'], true))) {
                    throw ValidationException::withMessages(['file' => 'Only rejected documents on applications awaiting a decision can be resubmitted.']);
                }
                $file = $documents->upload($request->user(), $request->file('file'), $requirement->name);
                $storedPath = $file->path;
                if (! $item->submitted_at) {
                    $documents->select($request->user(), $item, $requirement, ['version' => $item->workflow_version, 'document_id' => $file->id, 'confirmed' => true]);

                    return;
                }
                $previous->forceFill(['removed_at' => now()])->save();
                $attachment = ApplicationDocument::create(['application_id' => $item->id, 'scholarship_requirement_id' => $requirement->id, 'student_document_id' => $file->id, 'version' => $previous->version + 1, 'requirement_snapshot' => $previous->requirement_snapshot, 'checklist_completed_at' => now()]);
                $attachment->forceFill(['submitted_at' => now()])->save();
                $item->workflow_version++;
                $item->save();
                $request->user()->activityLogs()->create(['action' => 'application.document_resubmitted', 'subject_type' => ApplicationDocument::class, 'subject_id' => $attachment->id, 'changes' => ['previous_attachment_id' => $previous->id], 'occurred_at' => now()]);
                app(ScholarshipNotices::class)->send($item->scholarship->provider->user, $item->scholarship, 'Document correction submitted', $requirement->name.' was resubmitted for review.', $item);
            });
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('documents')->delete($storedPath);
            }
            throw $error;
        }

        return back()->with('status', 'Document uploaded and attached to this application.');
    }
}
