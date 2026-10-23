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
    public const FIELDS = ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'student_number', 'school', 'campus', 'campus_region_code', 'campus_province_code', 'campus_city_code', 'education_level', 'course', 'year_level', 'region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'];

    /** @return array<string, array<int, mixed>> */
    public function rules(array $input = []): array
    {
        return [
            'first_name' => InputRules::name(), 'surname' => InputRules::name(),
            'no_middle_name' => ['sometimes', 'boolean'],
            'middle_name' => ['nullable', ...array_map(fn ($rule) => $rule === 'required' ? Rule::requiredIf(! in_array($input['no_middle_name'] ?? false, [true, 1, '1'], true)) : $rule, InputRules::name())],
            'suffix' => ['nullable', Rule::enum(NameSuffix::class)],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'contact_number' => ['required', 'string', 'size:13', 'regex:/^\+63[0-9]{10}$/D'],
            'student_number' => ['required', 'string', 'max:80', InputRules::singleLine()],
            'school' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'campus' => ['exclude'],
            'campus_region_code' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'campus_province_code' => ['required', 'string', 'regex:/^(?:[0-9]{9}|none)$/D'],
            'campus_city_code' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'education_level' => ['required', Rule::enum(EducationLevel::class)],
            'course' => ['required', 'string', 'max:255', InputRules::singleLine(), Rule::exists('courses', 'name')->where('education_level', is_string($input['education_level'] ?? null) ? $input['education_level'] : '')],
            'year_level' => ['required', 'integer', Rule::in(array_map(fn (YearLevel $year): int => $year->value, array_filter(YearLevel::cases(), fn (YearLevel $year): bool => $year->educationLevel()->value === ($input['education_level'] ?? null))))],
            'region_code' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'province_code' => ['required', 'string', 'regex:/^(?:[0-9]{9}|none)$/D'],
            'city_code' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'barangay_code' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'street_address' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'terms_accepted' => ['required', 'accepted'], 'privacy_accepted' => ['required', 'accepted'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $input = InputRules::normalize($input, array_merge(self::FIELDS, ['name', 'email', 'organization_name']), ['email']);
        if (in_array($input['no_middle_name'] ?? false, [true, 1, '1'], true)) {
            $input['middle_name'] = null;
        }
        if (isset($input['contact_number']) && is_string($input['contact_number'])) {
            $input['contact_number'] = InputRules::contactNumber($input['contact_number']);
        }

        return $input;
    }

    /** @param array<string, mixed> $data @return array{campus: string} */
    public function campus(array $data): array
    {
        try {
            $names = $this->address(['region_code' => $data['campus_region_code'], 'province_code' => $data['campus_province_code'], 'city_code' => $data['campus_city_code']], false);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn (array $messages, string $field): array => ['campus_'.$field => $messages])->all());
        }
        $label = $names['city_name'].', '.($data['campus_province_code'] === 'none' ? $names['region_name'] : $names['province_name']);
        if (mb_strlen($label) > 255) {
            throw ValidationException::withMessages(['campus_city_code' => 'The selected campus location is too long to save. Contact the administrator.']);
        }

        return ['campus' => $label];
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
            $region = collect($locations->regions()['data'])->firstWhere('code', $data['region_code']);
            if (! $region) {
                throw ValidationException::withMessages(['region_code' => 'Select an available region.']);
            }
            $city = collect($locations->cities($region['code'])['data'])->firstWhere('code', $data['city_code']);
            if (! $city) {
                throw ValidationException::withMessages(['city_code' => 'Select a city or municipality belonging to your region.']);
            }
            $province = null;
            if ($data['province_code'] === 'none') {
                if (! empty($city['province_code'])) {
                    throw ValidationException::withMessages(['province_code' => 'Select the province associated with this city.']);
                }
            } else {
                $province = collect($locations->provinces($region['code'])['data'])->firstWhere('code', $data['province_code']);
                if (! $province || ($city['province_code'] ?? null) !== $province['code']) {
                    throw ValidationException::withMessages(['province_code' => 'The city and province must belong to the selected region and to each other.']);
                }
            }
            $barangay = $includeBarangay ? collect($locations->barangays($city['code'])['data'])->firstWhere('code', $data['barangay_code']) : null;
            if ($includeBarangay && ! $barangay) {
                throw ValidationException::withMessages(['barangay_code' => 'Select a barangay belonging to your city or municipality.']);
            }

            return ['region_name' => $region['name'], 'province_name' => $province['name'] ?? 'Not applicable (independent city)', 'city_name' => $city['name'], 'barangay_name' => $barangay['name'] ?? null];
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['region_code' => 'Address verification is temporarily unavailable. Your entries are kept; please retry.']);
        }
    }
}
