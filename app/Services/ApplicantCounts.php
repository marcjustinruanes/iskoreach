<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\ExternalApplicantCount;
use App\Models\Scholarship;

class ApplicantCounts
{
    public function snapshot(Scholarship $scholarship): array
    {
        $applications = $scholarship->applications()->whereIn('status', ApplicationStatus::countedValues())
            ->whereNotNull('submitted_at')->whereHas('student', fn ($query) => $query->where('role', UserRole::Student->value))
            ->orderBy('student_id')->cursor(['student_id', 'status']);
        $hash = hash_init('sha256');
        $internal = 0;
        $waitlisted = 0;
        foreach ($applications as $application) {
            $internal++;
            if ($application->status === ApplicationStatus::Waitlisted) {
                $waitlisted++;
            }
            hash_update($hash, $application->student_id.',');
        }

        return ['internal_count' => $internal, 'waitlisted_count' => $waitlisted, 'fingerprint' => hash_final($hash)];
    }

    public function summary(Scholarship $scholarship): array
    {
        $snapshot = $this->snapshot($scholarship);
        $latest = $scholarship->externalCounts()->whereNull('correction_of_id')->orderByDesc('id')->first();
        $verified = $scholarship->externalCounts()->where('status', 'approved')->orderByDesc('source_as_of')->orderByDesc('id')->first();
        $valid = $verified !== null && hash_equals($snapshot['fingerprint'], $verified->internal_fingerprint ?? '');
        $external = $valid ? ($verified->corrected_count ?? $verified->reported_count) : 0;

        return [
            'internal_count' => $snapshot['internal_count'], 'waitlisted_count' => $snapshot['waitlisted_count'],
            'reported_external_count' => $latest?->reported_count,
            'verified_external_count' => $external, 'public_count' => $snapshot['internal_count'] + $external,
            'needs_reverification' => $verified !== null && ! $valid,
            'latest_status' => $latest?->status ?? 'no_report', 'latest_report_id' => $latest?->id,
            'source_label' => $latest?->source_label, 'source_as_of' => $latest?->source_as_of?->toDateString(),
            'verified_source_label' => $valid ? $verified->source_label : null,
            'verified_source_as_of' => $valid ? $verified->source_as_of->toDateString() : null,
            'last_reported_at' => $latest?->created_at->toIso8601String(),
            'last_verified_at' => $verified?->reviewed_at?->toIso8601String(),
            'pending_reports' => $scholarship->externalCounts()->where('status', 'pending')->count(),
            'latest_approved_id' => $verified?->id,
            'review_token' => hash_hmac('sha256', $snapshot['fingerprint'], config('app.key')),
        ];
    }

    public function reportData(ExternalApplicantCount $report): array
    {
        return [
            'id' => $report->id, 'reported_count' => $report->reported_count, 'corrected_count' => $report->corrected_count,
            'source_label' => $report->source_label, 'source_as_of' => $report->source_as_of->toDateString(),
            'reporter' => $report->reporter?->name ?? 'Unavailable', 'status' => $report->status,
            'reviewer' => $report->reviewer?->name, 'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'reason' => $report->reason, 'correction_of_id' => $report->correction_of_id,
            'created_at' => $report->created_at->toIso8601String(),
        ];
    }
}
