<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Scholarship;

class ApplicantCounts
{
    public function snapshot(Scholarship $scholarship): array
    {
        $query = $scholarship->applications()->withValidApplicant()->whereIn('status', ApplicationStatus::countedValues())->whereNotNull('submitted_at');
        $internal = (clone $query)->where('applicant_source', 'internal')->count();
        $external = (clone $query)->where('applicant_source', 'external')->count();

        return ['internal_count' => $internal, 'external_count' => $external, 'waitlisted_count' => (clone $query)->where('status', 'waitlisted')->count()];
    }

    public function summary(Scholarship $scholarship): array
    {
        $snapshot = $this->snapshot($scholarship);

        return $snapshot + ['public_count' => $snapshot['internal_count'] + $snapshot['external_count']];
    }
}
