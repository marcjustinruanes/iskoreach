<?php

namespace App\Enums;

enum ScholarshipStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Archived = 'archived';
}
