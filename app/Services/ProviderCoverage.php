<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderCoverage
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'coverage_areas' => ['required', 'array', 'min:1', 'max:20'],
            'coverage_areas.*' => ['array:scope,region_name,province_name,city_name'],
            'coverage_areas.*.scope' => ['required', Rule::in(['nationwide', 'region', 'province', 'city'])],
            'coverage_areas.*.region_name' => ['nullable', 'string', 'max:255'],
            'coverage_areas.*.province_name' => ['nullable', 'string', 'max:255'],
            'coverage_areas.*.city_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param list<array<string, mixed>> $areas @return list<array<string, mixed>> */
    public function resolve(array $areas): array
    {
        $resolved = [];
        $seen = [];
        $firstScope = reset($areas)['scope'] ?? null;
        foreach ($areas as $index => $area) {
            $scope = $area['scope'];
            if ($scope !== $firstScope) {
                throw ValidationException::withMessages(["coverage_areas.$index.scope" => 'All coverage areas must use the same type as Coverage 1.']);
            }
            $area = ['scope' => $scope, 'region_name' => $scope === 'nationwide' ? '' : ($area['region_name'] ?? ''), 'province_name' => in_array($scope, ['province', 'city'], true) ? ($area['province_name'] ?? '') : '', 'city_name' => $scope === 'city' ? ($area['city_name'] ?? '') : ''];
            $key = implode('|', $area);
            if (in_array($key, $seen, true)) {
                throw ValidationException::withMessages(["coverage_areas.$index.scope" => 'This coverage area is already listed.']);
            }
            $seen[] = $key;
            try {
                $names = app(PhilippineLocations::class)->resolve($area);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(["coverage_areas.$index.scope" => $error->getMessage()]);
            } catch (\RuntimeException $error) {
                throw ValidationException::withMessages(["coverage_areas.$index.scope" => 'Coverage verification is temporarily unavailable. Your selections are kept; retry shortly.']);
            }
            $resolved[] = ['scope' => $scope, 'region_name' => $names['region_name'] ?? '', 'province_name' => $names['province_name'] ?? '', 'city_name' => $names['city_name'] ?? ''];
        }
        if (count($areas) > 1 && collect($areas)->contains('scope', 'nationwide')) {
            throw ValidationException::withMessages(['coverage_areas' => 'Choose nationwide on its own, or list specific coverage areas.']);
        }

        return $resolved;
    }
}
