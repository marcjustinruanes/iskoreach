<?php

namespace App\Enums;

enum EducationLevel: string
{
    case SeniorHigh = 'senior_high';
    case College = 'college';

    public function label(): string
    {
        return match ($this) {
            self::SeniorHigh => 'Senior high school',
            self::College => 'College',
        };
    }
}
