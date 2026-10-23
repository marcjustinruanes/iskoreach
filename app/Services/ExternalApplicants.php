<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExternalApplicants
{
    /** @param array{name: string, email: string} $data */
    public function store(User $provider, Scholarship $scholarship, array $data): Application
    {
        try {
            return DB::transaction(function () use ($provider, $scholarship, $data): Application {
                ProviderProfile::whereKey($scholarship->provider_id)->lockForUpdate()->firstOrFail();
                $program = Scholarship::whereKey($scholarship->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($provider)->authorize('reportExternalCount', $program);
                $email = strtolower(trim($data['email']));
                if (Application::withTrashed()->where('scholarship_id', $program->id)->whereRaw('LOWER(applicant_email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This person already has an application for this scholarship.']);
                }
                $application = new Application;
                $application->forceFill([
                    'scholarship_id' => $program->id, 'academic_term_id' => $program->academic_term_id,
                    'student_id' => null, 'applicant_source' => 'external', 'applicant_name' => trim($data['name']),
                    'applicant_email' => $email, 'recorded_by' => $provider->id,
                    'status' => 'submitted', 'submitted_at' => now(),
                    'submission_snapshot' => ['student' => ['name' => trim($data['name']), 'email' => $email], 'program' => ['title' => $program->title, 'version' => $program->management_version]],
                ])->save();
                $provider->activityLogs()->create(['action' => 'application.external_recorded', 'subject_type' => Application::class,
                    'subject_id' => $application->id, 'changes' => [], 'occurred_at' => now()]);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This person already has an application for this scholarship.']);
        }
    }
}
