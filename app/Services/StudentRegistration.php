<?php

namespace App\Services;

use App\Support\InputRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentRegistration
{
    public const POLICY_VERSION = '2026-10-22-draft-3';

    public const SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

    public const YEAR_LEVELS = [11, 12, 1, 2, 3, 4, 5, 6];

    public const FIELDS = ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'student_number', 'school', 'campus', 'college', 'course', 'year_level', 'enrollment_status', 'region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $optionalName = InputRules::name();
        $optionalName[1] = 'nullable';

        return [
            'first_name' => InputRules::name(), 'surname' => InputRules::name(), 'middle_name' => $optionalName,
            'suffix' => ['nullable', 'string', Rule::in(self::SUFFIXES)],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'sex' => ['required', Rule::in(['female', 'male'])],
            'contact_number' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/D'],
            'student_number' => ['required', 'string', 'max:80', InputRules::singleLine()],
            'school' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'campus' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'college' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'course' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'year_level' => ['required', 'integer', Rule::in(self::YEAR_LEVELS)],
            'enrollment_status' => ['required', Rule::in(['enrolled', 'not_enrolled', 'on_leave', 'graduated'])],
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
        if (isset($input['contact_number']) && is_string($input['contact_number'])) {
            $input['contact_number'] = preg_replace('/[ ()-]/', '', $input['contact_number']);
        }

        return $input;
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
