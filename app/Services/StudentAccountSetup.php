<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class StudentAccountSetup
{
    public const PERSONAL = ['first_name', 'surname', 'date_of_birth', 'sex', 'contact_number'];

    public const ACADEMIC = ['student_number', 'school', 'campus_region_name', 'campus_province_name', 'campus_city_name', 'education_level', 'course', 'year_level'];

    public const ADDRESS = ['region_name', 'province_name', 'city_name', 'barangay_name'];

    public function data(User $user): array
    {
        $profile = $user->studentProfile()->firstOrFail();
        $complete = fn (array $fields): bool => collect($fields)->every(fn ($field): bool => ($field === 'student_number' && $profile->education_level === 'senior_high' && filled($profile->graduation_year)) || filled($profile->$field));
        $steps = collect(['personal' => self::PERSONAL, 'academic' => self::ACADEMIC, 'address' => self::ADDRESS])->map(fn ($fields, $key): array => ['key' => $key, 'label' => ucfirst($key).' information', 'complete' => $complete($fields), 'url' => route('student.profile').'#'.$key])->values()->all();
        $count = collect($steps)->where('complete', true)->count();
        $photo = $profile->photo_path;
        $photoUrl = $photo && Storage::disk('profile_pictures')->exists($photo) ? Storage::disk('profile_pictures')->url($photo) : ($photo && Storage::disk('documents')->exists($photo) ? route('student.photo.show') : null);

        return ['steps' => $steps, 'completed' => $count, 'total' => count($steps), 'percentage' => (int) round($count / count($steps) * 100), 'photo_url' => $photoUrl];
    }
}
