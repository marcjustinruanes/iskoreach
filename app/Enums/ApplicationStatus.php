<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case NotStarted = 'not_started';
    case RequirementsPending = 'requirements_pending';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Waitlisted = 'waitlisted';

    public static function countedValues(): array
    {
        return ['submitted', 'under_review', 'accepted', 'rejected', 'waitlisted'];
    }
}
