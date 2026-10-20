<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PhilippineLocations
{
    public function regions(): array
    {
        return $this->lookup('regions/');
    }

    public function cities(string $region): array
    {
        if (! preg_match('/^\\d{9}$/D', $region) || ! collect($this->regions()['data'])->contains('code', $region)) {
            throw ValidationException::withMessages(['region' => 'Select an available region.']);
        }

        return $this->lookup('regions/'.$region.'/cities-municipalities/', $region);
    }

    private function lookup(string $path, ?string $region = null): array
    {
        $base = rtrim(config('services.psgc.url'), '/');
        $key = 'psgc.'.hash('sha256', $base.'/'.$path);
        if ($fresh = Cache::get($key.'.fresh')) {
            return $fresh;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->get($base.'/'.$path)->throw();
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 2000) {
                throw new RuntimeException('Invalid location response.');
            }
            $choices = [];
            $seen = [];
            foreach ($rows as $row) {
                $name = is_array($row) && is_string($row['name'] ?? null) ? trim(preg_replace('/[\p{Z}\s]+/u', ' ', $row['name'])) : null;
                if (! is_array($row) || ! is_string($row['code'] ?? null) || ! preg_match('/^\\d{9}$/D', $row['code']) || $name === null || $name === '' || mb_strlen($name) > 255 || isset($seen[$row['code']]) || ($region !== null && ($row['regionCode'] ?? null) !== $region)) {
                    throw new RuntimeException('Invalid location record.');
                }
                $choices[] = ['code' => $row['code'], 'name' => $name];
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
        $region = collect($this->regions()['data'])->firstWhere('code', $location['region_code']);
        if (! $region) {
            throw ValidationException::withMessages(['location' => 'Choose a region from the available location options.']);
        }
        $city = null;
        if ($location['scope'] === 'city') {
            $city = collect($this->cities($region['code'])['data'])->firstWhere('code', $location['city_code']);
            if (! $city) {
                throw ValidationException::withMessages(['location' => 'Choose a city or municipality belonging to the selected region.']);
            }
        }

        return ['region_name' => $region['name'], 'city_name' => $city['name'] ?? null];
    }
}
