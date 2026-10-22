<?php

namespace App\Enums;

enum AwardStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Revoked = 'revoked';
}
