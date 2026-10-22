<?php

namespace App\Services;

use App\Models\StudentDocument;
use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class StudentAccountSetup
{
    public const PERSONAL = ['first_name', 'surname', 'date_of_birth', 'sex', 'contact_number'];

    public const ACADEMIC = ['student_number', 'school', 'college', 'course', 'year_level', 'enrollment_status'];

    public const ADDRESS = ['region_code', 'province_code', 'city_code', 'barangay_code'];

    public function fileAvailable(?string $path): bool
    {
        return $path && Storage::disk('documents')->exists($path);
    }

    public function invalidate(User $user): void
    {
        foreach (StudentVerification::where('user_id', $user->id)->whereIn('status', ['pending', 'verified'])->lockForUpdate()->get() as $item) {
            $item->update(['status' => $item->status === 'verified' ? 'revoked' : 'rejected', 'reason' => 'Profile or evidence changed. Review your information and reapply.', 'reviewed_at' => now()]);
        }
    }

    public function data(User $user): array
    {
        $profile = $user->studentProfile()->firstOrFail();
        $complete = fn (array $fields): bool => collect($fields)->every(fn ($field): bool => filled($profile->$field));
        $steps = collect(['personal' => self::PERSONAL, 'academic' => self::ACADEMIC, 'address' => self::ADDRESS])->map(fn ($fields, $key): array => ['key' => $key, 'label' => ucfirst($key).' information', 'complete' => $complete($fields), 'url' => route('student.profile').'#'.$key])->values()->all();
        $steps[] = ['key' => 'email', 'label' => 'Verify email', 'complete' => $user->hasVerifiedEmail(), 'url' => route('student.email-code')];
        $count = collect($steps)->where('complete', true)->count();
        $conditions = $steps;
        $conditions[] = ['key' => 'current_enrollment', 'label' => 'Currently enrolled', 'complete' => $profile->enrollment_status === 'enrolled', 'url' => route('student.profile').'#academic'];
        if (config('student-verification.require_photo', true)) {
            $conditions[] = ['key' => 'photo', 'label' => 'Upload a profile picture', 'complete' => $this->fileAvailable($profile->photo_path), 'url' => route('student.profile').'#verification'];
        }
        $documents = [];
        foreach (config('student-verification.documents') as $kind => $label) {
            $document = StudentDocument::where('student_id', $user->id)->find($profile->verification_documents[$kind] ?? 0);
            $available = $document && $this->fileAvailable($document->path) && hash_equals($document->checksum, hash_file('sha256', Storage::disk('documents')->path($document->path)));
            $documents[] = ['kind' => $kind, 'label' => $label, 'complete' => (bool) $available, 'name' => $document?->original_name];
            $conditions[] = ['key' => $kind, 'label' => $label, 'complete' => (bool) $available, 'url' => route('student.profile').'#verification'];
        }
        $eligible = collect($conditions)->every(fn ($row): bool => $row['complete']);
        $latest = StudentVerification::where('user_id', $user->id)->latest('id')->first();

        return ['steps' => $steps, 'completed' => $count, 'total' => count($steps), 'percentage' => (int) round($count / count($steps) * 100), 'email_verified' => $user->hasVerifiedEmail(), 'photo_url' => ($profile->photo_path && ($this->fileAvailable($profile->photo_path) || Storage::disk('profile_pictures')->exists($profile->photo_path))) ? (Storage::disk('profile_pictures')->exists($profile->photo_path) ? Storage::disk('profile_pictures')->url($profile->photo_path) : route('student.photo.show')) : null,
            'verification' => ['status' => ($latest?->status === 'verified' && ! $eligible) ? 'suspended' : ($latest?->status ?? ((filled($profile->photo_path) || filled($profile->verification_documents)) ? 'incomplete' : 'not_applied')), 'reason' => $latest?->reason, 'id' => $latest?->id, 'eligible' => collect($conditions)->every(fn ($row): bool => $row['complete']), 'conditions' => $conditions, 'documents' => $documents]];
    }
}
