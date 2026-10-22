<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class ScholarshipLocationSearch
{
    public function apply(Builder $query, string $search): Builder
    {
        $search = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $search));
        if ($search === '') {
            return $query;
        }

        return $query->whereHas('locations', function (Builder $locations) use ($search): void {
            $locations->where('scope', 'nationwide')->orWhere(function (Builder $local) use ($search): void {
                foreach (explode(' ', mb_strtolower($search)) as $token) {
                    $pattern = '%'.strtr($token, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                    $local->where(function (Builder $fields) use ($pattern): void {
                        foreach (['region_code', 'city_code', 'region_name', 'city_name', 'province_code', 'province_name'] as $column) {
                            $fields->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                        }
                    });
                }
            });
        });
    }
}
