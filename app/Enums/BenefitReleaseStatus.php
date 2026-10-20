<?php

namespace App\Enums;

enum BenefitReleaseStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Released = 'released';
}
