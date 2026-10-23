<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PhilippineLocations
{
    public const NO_PROVINCE = 'Not applicable (independent city)';

    public function choice(array $choices, ?string $value): ?array
    {
        return collect($choices)->first(fn (array $choice): bool => $choice['code'] === $value || $choice['name'] === $value);
    }

    public function cityChoice(string $region, ?string $city, ?string $province = null): ?array
    {
        $matches = array_values(array_filter($this->cities($region)['data'], fn (array $choice): bool => $choice['name'] === $city || $choice['code'] === $city));
        if (count($matches) < 2) {
            return $matches[0] ?? null;
        }
        if (! filled($province)) {
            throw ValidationException::withMessages(['province_name' => 'Select the province to distinguish cities or municipalities with the same name.']);
        }
        $provinceCode = in_array($province, ['none', self::NO_PROVINCE], true) ? null : ($this->choice($this->provinces($region)['data'], $province)['code'] ?? 'unknown');

        return collect($matches)->first(fn (array $choice): bool => ($choice['province_code'] ?? null) === $provinceCode);
    }

    public function namedOptions(string $kind, ?string $region = null, ?string $city = null, ?string $province = null): array
    {
        $regionChoice = $region ? $this->choice($this->regions()['data'], $region) : null;
        if ($region !== null && ! $regionChoice) {
            throw ValidationException::withMessages(['region' => 'Select an available region.']);
        }
        $result = match ($kind) {
            'regions' => $this->regions(),
            'provinces' => $this->provinces($regionChoice['code']),
            'cities' => $this->cities($regionChoice['code']),
            'barangays' => $this->barangays($this->cityChoice($regionChoice['code'], $city, $province)['code'] ?? ''),
        };
        $provinces = $kind === 'cities' ? $this->provinces($regionChoice['code'])['data'] : [];
        $result['data'] = array_map(function (array $choice) use ($provinces): array {
            return ['code' => $choice['name'], 'name' => $choice['name'], 'province_name' => isset($choice['province_code']) ? ($this->choice($provinces, $choice['province_code'])['name'] ?? null) : null];
        }, $result['data']);

        return $result;
    }

    public function regions(): array
    {
        return $this->lookup('regions/');
    }

    public function provinces(string $region): array
    {
        if (! preg_match('/^\\d{9}$/D', $region) || ! collect($this->regions()['data'])->contains('code', $region)) {
            throw ValidationException::withMessages(['region' => 'Select an available region.']);
        }

        return $this->lookup('regions/'.$region.'/provinces/', $region);
    }

    public function cities(string $region): array
    {
        if (! preg_match('/^\\d{9}$/D', $region) || ! collect($this->regions()['data'])->contains('code', $region)) {
            throw ValidationException::withMessages(['region' => 'Select an available region.']);
        }

        return $this->lookup('regions/'.$region.'/cities-municipalities/', $region);
    }

    public function barangays(string $city): array
    {
        if (! preg_match('/^\\d{9}$/D', $city)) {
            throw ValidationException::withMessages(['city_code' => 'Select a valid city or municipality.']);
        }

        return $this->lookup('cities-municipalities/'.$city.'/barangays/', city: $city);
    }

    private function lookup(string $path, ?string $region = null, ?string $city = null): array
    {
        $base = rtrim(config('services.psgc.url'), '/');
        $key = 'psgc.v2.'.hash('sha256', $base.'/'.$path);
        if ($fresh = Cache::get($key.'.fresh')) {
            return $fresh;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->get($base.'/'.$path)->throw();
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows) || (count($rows) < 1 && ! str_ends_with($path, '/provinces/')) || count($rows) > 2000) {
                throw new RuntimeException('Invalid location response.');
            }
            $choices = [];
            $seen = [];
            foreach ($rows as $row) {
                $name = is_array($row) && is_string($row['name'] ?? null) ? trim(preg_replace('/[\p{Z}\s]+/u', ' ', $row['name'])) : null;
                if (! is_array($row) || ! is_string($row['code'] ?? null) || ! preg_match('/^\\d{9}$/D', $row['code']) || $name === null || $name === '' || mb_strlen($name) > 255 || isset($seen[$row['code']]) || ($region !== null && ($row['regionCode'] ?? null) !== $region) || ($city !== null && ($row['cityCode'] ?? null) !== $city && ($row['municipalityCode'] ?? null) !== $city)) {
                    throw new RuntimeException('Invalid location record.');
                }
                $choices[] = ['code' => $row['code'], 'name' => $name] + (isset($row['provinceCode']) && is_string($row['provinceCode']) && preg_match('/^\\d{9}$/D', $row['provinceCode']) ? ['province_code' => $row['provinceCode']] : []);
                $seen[$row['code']] = true;
            }
            usort($choices, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $result = ['data' => $choices, 'stale' => false, 'fetched_at' => now()->toIso8601String(), 'source' => 'PSGC API (community maintained)'];
            Cache::put($key.'.fresh', $result, now()->addHours(6));
            Cache::put($key.'.last', $result, now()->addDays(30));

            return $result;
        } catch (\Throwable $error) {
            if ($last = Cache::get($key.'.last')) {
                return [...$last, 'stale' => true];
            }
            throw new RuntimeException('Location options are temporarily unavailable. Please retry shortly.', previous: $error);
        }
    }

    public function resolve(array $location): array
    {
        if ($location['scope'] === 'nationwide') {
            return ['region_name' => null, 'city_name' => null];
        }
        $region = $this->choice($this->regions()['data'], $location['region_name'] ?? $location['region_code'] ?? null);
        if (! $region) {
            throw ValidationException::withMessages(['location' => 'Choose a region from the available location options.']);
        }
        if ($location['scope'] === 'province') {
            $province = $this->choice($this->provinces($region['code'])['data'], $location['province_name'] ?? $location['province_code'] ?? null);
            if (! $province) {
                throw ValidationException::withMessages(['location' => 'Choose a province belonging to the selected region.']);
            }

            return ['region_name' => $region['name'], 'city_name' => null, 'province_name' => $province['name']];
        }
        $city = null;
        if ($location['scope'] === 'city') {
            $city = $this->cityChoice($region['code'], $location['city_name'] ?? $location['city_code'] ?? null, $location['province_name'] ?? $location['province_code'] ?? null);
            if (! $city) {
                throw ValidationException::withMessages(['location' => 'Choose a city or municipality belonging to the selected region.']);
            }
        }

        $province = $city && isset($city['province_code']) ? collect($this->provinces($region['code'])['data'])->firstWhere('code', $city['province_code']) : null;

        return ['region_name' => $region['name'], 'city_name' => $city['name'] ?? null, 'province_name' => $province['name'] ?? null];
    }
}
