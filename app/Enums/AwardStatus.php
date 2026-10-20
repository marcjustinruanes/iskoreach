<?php

namespace App\Enums;

enum AwardStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Revoked = 'revoked';
}
