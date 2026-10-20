<?php

namespace App\Services;

use Illuminate\Database\Connection;
use InvalidArgumentException;

class GeographicDirectory
{
    public function options(Connection $connection, int $dataset, string $level, ?string $parentCode = null): array
    {
        if (! in_array($level, ['region', 'province', 'city', 'municipality', 'barangay'], true)) {
            throw new InvalidArgumentException('Unsupported geographic level.');
        }
        $this->dataset($connection, $dataset);
        $query = $connection->table('geographic_places')->where('dataset_id', $dataset)->where('level', $level);
        if ($parentCode !== null) {
            $parent = $connection->table('geographic_places')->where('dataset_id', $dataset)->where('code', $parentCode)->first();
            $field = match ($parent?->level) {
                'region' => 'region_code', 'province' => 'province_code', 'city', 'municipality' => 'city_code', default => null,
            };
            if ($field === null) {
                throw new InvalidArgumentException('Choose a valid parent from this dataset edition.');
            }
            $query->where($field, $parentCode);
        }

        return ['dataset_id' => $dataset, 'places' => $query->orderBy('name')->orderBy('code')->get(['code', 'name', 'level', 'region_code', 'province_code', 'city_code'])->map(fn ($row): array => (array) $row)->all()];
    }

    public function correspondenceCandidates(Connection $connection, int $dataset, string $legacy): array
    {
        $this->dataset($connection, $dataset);

        return $connection->table('geographic_correspondences')->where('dataset_id', $dataset)->where('legacy_code', $legacy)
            ->orderBy('place_code')->get(['place_code', 'source_ref'])->map(fn ($row): array => (array) $row)->all();
    }

    private function dataset(Connection $connection, int $dataset): void
    {
        if (! $connection->table('geographic_datasets')->where('id', $dataset)->where('state', 'validated_staging')->exists()) {
            throw new InvalidArgumentException('The explicitly requested staging dataset is unavailable.');
        }
    }
}
