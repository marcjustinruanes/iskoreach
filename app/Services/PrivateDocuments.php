<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PrivateDocuments
{
    public function uploadLimitKilobytes(): int
    {
        $limits = [10240];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = trim(ini_get($setting));
            $bytes = (float) $value * match (strtolower(substr($value, -1))) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
            if ($bytes > 0) {
                $limits[] = (int) floor($bytes / 1024);
            }
        }

        return max(1, min($limits));
    }

    public function upload(User $student, UploadedFile $file, string $label): StudentDocument
    {
        $path = $file->store('vault/'.$student->id, 'documents');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }
        try {
            return DB::transaction(function () use ($student, $file, $label, $path): StudentDocument {
                User::query()->lockForUpdate()->findOrFail($student->id);
                $document = StudentDocument::create(['student_id' => $student->id, 'label' => $label,
                    'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $file->getClientOriginalName()), 0, 240),
                    'path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath())]);
                $this->audit($student, 'document.uploaded', $document->id, StudentDocument::class);

                return $document;
            });
        } catch (\Throwable $error) {
            Storage::disk('documents')->delete($path);
            throw $error;
        }
    }

    public function removeVault(User $student, StudentDocument $document): void
    {
        DB::transaction(function () use ($student, $document): void {
            User::query()->lockForUpdate()->findOrFail($student->id);
            $item = StudentDocument::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($student)->authorize('view', $item);
            if (in_array($item->id, array_values($student->studentProfile?->verification_documents ?? []), true)) {
                app(StudentAccountSetup::class)->invalidate($student);
            }
            $item->delete();
            $this->audit($student, 'document.removed', $item->id, StudentDocument::class);
        });
    }

    public function select(User $student, Application $application, ScholarshipRequirement $requirement, array $data, bool $remove = false): void
    {
        DB::transaction(function () use ($student, $application, $requirement, $data, $remove): void {
            ProviderProfile::query()->lockForUpdate()->findOrFail($application->scholarship->provider_id);
            $program = Scholarship::withTrashed()->lockForUpdate()->findOrFail($application->scholarship_id);
            User::query()->lockForUpdate()->findOrFail($student->id);
            $item = Application::query()->lockForUpdate()->findOrFail($application->id);
            Gate::forUser($student)->authorize('view', $item);
            if ($item->submitted_at !== null || ! in_array($item->status->value, ['not_started', 'requirements_pending'], true) || $program->trashed()) {
                throw ValidationException::withMessages(['document_id' => 'Only current application drafts can change attachments.']);
            }
            if ($item->workflow_version !== $data['version']) {
                throw ValidationException::withMessages(['version' => 'This application changed. Reload before changing documents.']);
            }
            $rule = $program->requirements()->where('requirement_stage', 'application')->find($requirement->id);
            if (! $rule) {
                throw ValidationException::withMessages(['document_id' => 'Choose an application requirement from this scholarship.']);
            }
            $latest = ApplicationDocument::where('application_id', $item->id)->where('scholarship_requirement_id', $rule->id)->orderByDesc('version')->first();
            if ($remove) {
                if (! $latest || $latest->removed_at) {
                    throw ValidationException::withMessages(['document_id' => 'No current attachment is available to remove.']);
                }
                $latest->removed_at = now();
                $latest->save();
            } else {
                $source = StudentDocument::query()->where('student_id', $student->id)->lockForUpdate()->find($data['document_id']);
                if (! $source || ! $this->usable($source, $rule)) {
                    throw ValidationException::withMessages(['document_id' => 'Select your available PDF, JPG, or PNG document within this requirement’s format and size limits.']);
                }
                ApplicationDocument::create(['application_id' => $item->id, 'scholarship_requirement_id' => $rule->id, 'student_document_id' => $source->id, 'version' => ($latest?->version ?? 0) + 1,
                    'requirement_snapshot' => $rule->only('name', 'description', 'required', 'allowed_mime_types', 'max_size_kb', 'requirement_stage'),
                    'checklist_completed_at' => now()]);
            }
            $complete = collect($this->checklist($item))->where('required', true)->every(fn (array $row): bool => $row['complete']);
            $fromStatus = $item->status->value;
            $item->status = $complete ? ApplicationStatus::NotStarted : ApplicationStatus::RequirementsPending;
            $item->workflow_version++;
            $item->save();
            if ($fromStatus !== $item->status->value) {
                ApplicationStatusHistory::create(['application_id' => $item->id, 'from_status' => $fromStatus, 'to_status' => $item->status->value, 'changed_by' => $student->id, 'reason' => 'Document checklist readiness changed.', 'occurred_at' => now()]);
            }
            $this->audit($student, $remove ? 'application.document_removed' : 'application.document_attached', $item->id, Application::class);
        });
    }

    public function usable(StudentDocument $document, ScholarshipRequirement $requirement): bool
    {
        $disk = Storage::disk('documents');

        return in_array($document->mime_type, $requirement->allowed_mime_types ?? [], true)
            && $document->size_bytes <= min($requirement->max_size_kb ?? 0, 10240) * 1024
            && $disk->exists($document->path) && $disk->size($document->path) === $document->size_bytes
            && hash_equals($document->checksum, hash_file('sha256', $disk->path($document->path)));
    }

    public function checklist(Application $application): array
    {
        return $application->scholarship->requirements()->where('requirement_stage', 'application')->get()->map(function ($rule) use ($application): array {
            $latest = ApplicationDocument::where('application_id', $application->id)->where('scholarship_requirement_id', $rule->id)->with('document')->orderByDesc('version')->first();
            $attached = $latest && ! $latest->removed_at;

            return [...$rule->only('id', 'name', 'description', 'required', 'allowed_mime_types', 'max_size_kb'),
                'complete' => $attached && $this->usable($latest->document, $rule),
                'attachment' => $attached ? $this->attachmentData($latest) : null];
        })->all();
    }

    public function assertReady(Application $application): void
    {
        foreach ($this->checklist($application) as $row) {
            if (($row['required'] || $row['attachment']) && ! $row['complete']) {
                throw ValidationException::withMessages(['application' => 'Complete required document attachments and replace or remove unavailable/incompatible optional attachments before submitting.']);
            }
        }
    }

    public function freeze(Application $application): void
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['attachment']) {
                ApplicationDocument::whereKey($row['attachment']['id'])->update(['submitted_at' => now()]);
            }
        }
    }

    public function submitted(Application $application): array
    {
        return ApplicationDocument::where('application_id', $application->id)->whereNotNull('submitted_at')->whereNull('removed_at')->with('document')->orderBy('id')->get()->map($this->attachmentData(...))->all();
    }

    public function vaultData(StudentDocument $document): array
    {
        return $document->only('id', 'label', 'original_name', 'mime_type', 'size_bytes') + ['uploaded_at' => $document->created_at->toIso8601String(), 'download_url' => route('student.documents.download', $document)];
    }

    private function attachmentData(ApplicationDocument $attachment): array
    {
        return ['id' => $attachment->id, 'name' => $attachment->requirement_snapshot['name'], 'file_name' => $attachment->document->original_name,
            'submitted' => $attachment->submitted_at !== null, 'review_status' => $attachment->review_status, 'review_notes' => $attachment->review_notes, 'version' => $attachment->version, 'size_bytes' => $attachment->document->size_bytes, 'download_url' => route('application-documents.download', $attachment)];
    }

    private function audit(User $student, string $action, int $id, string $type): void
    {
        $student->activityLogs()->create(['action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'changes' => [], 'occurred_at' => now()]);
    }
}
