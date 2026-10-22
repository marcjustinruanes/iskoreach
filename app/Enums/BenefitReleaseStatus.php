<?php

namespace App\Enums;

enum BenefitReleaseStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delayed = 'delayed';
    case Failed = 'failed';
    case Released = 'released';
}
