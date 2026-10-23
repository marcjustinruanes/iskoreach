<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Enums\NameSuffix;
use App\Enums\Sex;
use App\Enums\YearLevel;
use App\Support\InputRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentRegistration
{
    public function schoolLocation(array $data): string
    {
        return implode(', ', array_unique(array_filter([$data['campus_city_name'] ?? null,
            ($data['campus_province_name'] ?? null) === PhilippineLocations::NO_PROVINCE ? null : ($data['campus_province_name'] ?? null), $data['campus_region_name'] ?? null])));
    }

    public const FIELDS = ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'student_number', 'school', 'campus_region_name', 'campus_province_name', 'campus_city_name', 'education_level', 'course', 'year_level', 'graduation_year', 'region_name', 'province_name', 'city_name', 'barangay_name', 'street_address'];

    /** @return array<string, array<int, mixed>> */
    public function rules(array $input = []): array
    {
        $graduate = ($input['education_level'] ?? '') === 'senior_high' && in_array($input['shs_graduate'] ?? false, [true, 1, '1'], true);

        return [
            'first_name' => InputRules::name(), 'surname' => InputRules::name(),
            'no_middle_name' => ['sometimes', 'boolean'],
            'middle_name' => ['nullable', ...array_map(fn ($rule) => $rule === 'required' ? Rule::requiredIf(! in_array($input['no_middle_name'] ?? false, [true, 1, '1'], true)) : $rule, InputRules::name())],
            'suffix' => ['nullable', Rule::enum(NameSuffix::class)],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'contact_number' => ['required', 'string', 'size:13', 'regex:/^\+63[0-9]{10}$/D'],
            'student_number' => [$graduate ? 'nullable' : 'required', 'string', 'max:80', InputRules::singleLine(), Rule::unique('student_profiles', 'student_number')],
            'school' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'campus_region_name' => ['required', 'string', 'max:255'],
            'campus_province_name' => ['required', 'string', 'max:255'],
            'campus_city_name' => ['required', 'string', 'max:255'],
            'shs_graduate' => ['sometimes', 'boolean'],
            'graduation_year' => [Rule::requiredIf($graduate), $graduate ? 'required' : 'prohibited', 'nullable', 'integer', 'between:1900,'.now()->year],
            'education_level' => ['required', Rule::enum(EducationLevel::class)],
            'course' => ['required', 'string', 'max:255', InputRules::singleLine(), Rule::exists('courses', 'name')->where('education_level', is_string($input['education_level'] ?? null) ? $input['education_level'] : '')],
            'year_level' => ['required', 'integer', Rule::in(array_map(fn (YearLevel $year): int => $year->value, array_filter(YearLevel::cases(), fn (YearLevel $year): bool => $year->educationLevel()->value === ($input['education_level'] ?? null))))],
            'region_name' => ['required', 'string', 'max:255'],
            'province_name' => ['required', 'string', 'max:255'],
            'city_name' => ['required', 'string', 'max:255'],
            'barangay_name' => ['required', 'string', 'max:255'],
            'street_address' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'terms_accepted' => ['required', 'accepted'], 'privacy_accepted' => ['required', 'accepted'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $input = InputRules::normalize($input, array_merge(self::FIELDS, ['name', 'email', 'organization_name']), ['email']);
        if (($input['education_level'] ?? '') === 'senior_high' && in_array($input['shs_graduate'] ?? false, [true, 1, '1'], true)) {
            $input['year_level'] = 12;
        }
        if (in_array($input['no_middle_name'] ?? false, [true, 1, '1'], true)) {
            $input['middle_name'] = null;
        }
        if (isset($input['contact_number']) && is_string($input['contact_number'])) {
            $input['contact_number'] = InputRules::contactNumber($input['contact_number']);
        }

        return $input;
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    public function campus(array $data): array
    {
        try {
            $names = $this->address(['region_name' => $data['campus_region_name'], 'province_name' => $data['campus_province_name'], 'city_name' => $data['campus_city_name']], false);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn (array $messages, string $field): array => ['campus_'.$field => $messages])->all());
        }

        return ['campus_region_name' => $names['region_name'], 'campus_province_name' => $names['province_name'], 'campus_city_name' => $names['city_name']];
    }

    /** @param array<string, mixed> $data */
    public function name(array $data): string
    {
        $name = implode(' ', array_filter([$data['first_name'], $data['middle_name'] ?? null, $data['surname'], $data['suffix'] ?? null], fn ($part): bool => $part !== null && $part !== ''));
        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['first_name' => 'The combined name must not exceed 255 characters.']);
        }

        return $name;
    }

    /** @param array<string, mixed> $data @return array<string, ?string> */
    public function address(array $data, bool $includeBarangay = true): array
    {
        $locations = app(PhilippineLocations::class);
        try {
            $region = $locations->choice($locations->regions()['data'], $data['region_name']);
            if (! $region) {
                throw ValidationException::withMessages(['region_name' => 'Select an available region.']);
            }
            $city = $locations->cityChoice($region['code'], $data['city_name'], $data['province_name']);
            if (! $city) {
                throw ValidationException::withMessages(['city_name' => 'Select a city or municipality belonging to your region.']);
            }
            $province = null;
            if (in_array($data['province_name'], ['none', PhilippineLocations::NO_PROVINCE], true)) {
                if (! empty($city['province_code'])) {
                    throw ValidationException::withMessages(['province_name' => 'Select the province associated with this city.']);
                }
            } else {
                $province = $locations->choice($locations->provinces($region['code'])['data'], $data['province_name']);
                if (! $province || ($city['province_code'] ?? null) !== $province['code']) {
                    throw ValidationException::withMessages(['province_name' => 'The city and province must belong to the selected region and to each other.']);
                }
            }
            $barangay = $includeBarangay ? $locations->choice($locations->barangays($city['code'])['data'], $data['barangay_name']) : null;
            if ($includeBarangay && ! $barangay) {
                throw ValidationException::withMessages(['barangay_name' => 'Select a barangay belonging to your city or municipality.']);
            }

            return ['region_name' => $region['name'], 'province_name' => $province['name'] ?? 'Not applicable (independent city)', 'city_name' => $city['name'], 'barangay_name' => $barangay['name'] ?? null];
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['region_name' => 'Address verification is temporarily unavailable. Your entries are kept; please retry.']);
        }
    }
}
