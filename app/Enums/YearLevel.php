<?php

namespace App\Enums;

enum YearLevel: int
{
    case First = 1;
    case Second = 2;
    case Third = 3;
    case Fourth = 4;
    case Fifth = 5;
    case Sixth = 6;
    case GradeEleven = 11;
    case GradeTwelve = 12;

    public function educationLevel(): EducationLevel
    {
        return match ($this) {
            self::GradeEleven, self::GradeTwelve => EducationLevel::SeniorHigh,
            default => EducationLevel::College,
        };
    }

    public function label(): string
    {
        return ($this->educationLevel() === EducationLevel::SeniorHigh ? 'Grade ' : 'Year ').$this->value;
    }
}
