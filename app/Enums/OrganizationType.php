<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Foundation = 'foundation';
    case Government = 'government';
    case School = 'school';
    case Organization = 'organization';
    case Individual = 'individual';
    case LocalGovernment = 'lgu';
    case Nonprofit = 'nonprofit';
    case Corporate = 'corporate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual scholarship administrator',
            self::LocalGovernment => 'Local government unit',
            default => ucfirst($this->value),
        };
    }
}
