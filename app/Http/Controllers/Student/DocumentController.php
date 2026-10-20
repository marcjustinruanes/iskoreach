<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\DocumentMutationRequest;
use App\Http\Requests\Student\UploadDocumentRequest;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Services\PrivateDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request, PrivateDocuments $documents): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $search = $filters['q'] ?? '';

        return Inertia::render('student/Documents', ['filters' => ['q' => $search], 'upload_limit_kb' => $documents->uploadLimitKilobytes(),
            'documents' => StudentDocument::where('student_id', $request->user()->id)->when($search !== '', fn ($query) => $query->whereLike('label', '%'.$search.'%'))->latest()->paginate(10)->withQueryString()->through($documents->vaultData(...))]);
    }

    public function store(UploadDocumentRequest $request, PrivateDocuments $documents): RedirectResponse
    {
        $documents->upload($request->user(), $request->file('file'), $request->validated('label'));

        return to_route('student.documents.index')->with('status', 'Document uploaded to your private vault.');
    }

    public function destroy(DocumentMutationRequest $request, StudentDocument $document, PrivateDocuments $documents): RedirectResponse
    {
        $documents->removeVault($request->user(), $document);

        return to_route('student.documents.index')->with('status', 'Removed from your vault. Existing application attachments retain their files.');
    }

    public function attach(DocumentMutationRequest $request, Application $application, ScholarshipRequirement $requirement, PrivateDocuments $documents): RedirectResponse
    {
        $documents->select($request->user(), $application, $requirement, $request->validated(), $request->isMethod('delete'));

        return to_route('student.applications.show', $application)->with('status', 'Application document checklist updated.');
    }

    public function download(StudentDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return $this->file($document);
    }

    public function downloadAttachment(ApplicationDocument $applicationDocument): StreamedResponse
    {
        Gate::authorize('view', $applicationDocument);

        return $this->file($applicationDocument->document);
    }

    private function file(StudentDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('documents')->exists($document->path), 404, 'The stored file is unavailable.');
        abort_unless(hash_equals($document->checksum, hash_file('sha256', Storage::disk('documents')->path($document->path))), 404, 'The stored file is unavailable.');

        return Storage::disk('documents')->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
