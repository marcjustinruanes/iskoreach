<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Scholarship;
use Illuminate\Validation\ValidationException;

class ScholarshipCapacity
{
    /** @return array{slots: ?int, waitlist_slots: ?int, applications: int, waitlisted: int, application_remaining: ?int, waitlist_remaining: ?int} */
    public function summary(Scholarship $scholarship, ?int $excludeApplication = null): array
    {
        $settings = $scholarship->eligibility_rules['provider_settings'] ?? [];
        $slots = isset($settings['slots']) && $settings['slots'] !== '' ? (int) $settings['slots'] : null;
        $waitlist = isset($settings['waitlist_slots']) && $settings['waitlist_slots'] !== '' ? (int) $settings['waitlist_slots'] : null;
        $query = $scholarship->applications()->withValidApplicant()->whereNotNull('submitted_at')->when($excludeApplication !== null, fn ($query) => $query->whereKeyNot($excludeApplication));
        $applications = (clone $query)->whereIn('status', ['submitted', 'under_review', 'accepted'])->count();
        $waitlisted = (clone $query)->where('status', 'waitlisted')->count();

        return ['slots' => $slots, 'waitlist_slots' => $waitlist, 'applications' => $applications, 'waitlisted' => $waitlisted,
            'application_remaining' => $slots === null ? null : max(0, $slots - $applications), 'waitlist_remaining' => $waitlist === null ? null : max(0, $waitlist - $waitlisted)];
    }

    public function nextStatus(Scholarship $scholarship, bool $existingScholar = false): ?ApplicationStatus
    {
        $capacity = $this->summary($scholarship);
        if (! $existingScholar && $capacity['application_remaining'] !== 0) {
            return ApplicationStatus::Submitted;
        }

        return $capacity['waitlist_remaining'] !== 0 ? ApplicationStatus::Waitlisted : null;
    }

    public function assertReviewCapacity(Application $application, string $status): void
    {
        $capacity = $this->summary($application->scholarship, $application->id);
        $full = $status === 'waitlisted' ? $capacity['waitlist_remaining'] === 0 : (in_array($status, ['submitted', 'under_review', 'accepted'], true) && $capacity['application_remaining'] === 0);
        if ($full) {
            throw ValidationException::withMessages(['status' => $status === 'waitlisted' ? 'The waitlist is full.' : 'Application slots are full. A slot must become available before promoting a waitlisted applicant.']);
        }
    }
}
