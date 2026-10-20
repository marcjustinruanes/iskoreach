<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Provider = 'provider';
    case Admin = 'admin';
}
