<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

class GeographicSnapshot
{
    private const LEVELS = ['region', 'province', 'city', 'municipality', 'barangay'];

    public function inspect(string $manifestPath): array
    {
        $path = realpath($manifestPath);
        $this->require($path !== false && is_file($path), 'Manifest file is missing.');
        $bytes = $this->read($path, 1048576);
        $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $this->require(is_array($manifest) && ($manifest['schema_version'] ?? null) === 1, 'Unsupported manifest schema.');
        foreach (['edition', 'publisher', 'source_url', 'attribution', 'terms_url', 'transformation', 'parser_version'] as $field) {
            $this->require(is_string($manifest[$field] ?? null) && trim($manifest[$field]) !== '' && mb_strlen($manifest[$field]) <= 1000, 'Missing or invalid manifest field: '.$field);
        }
        $this->require(mb_strlen($manifest['edition']) <= 160, 'Edition label is too long.');
        foreach (['source_url', 'terms_url'] as $field) {
            $this->require(filter_var($manifest[$field], FILTER_VALIDATE_URL) && str_starts_with($manifest[$field], 'https://'), 'Manifest URLs must use HTTPS.');
        }
        foreach (['as_of', 'released_on'] as $field) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $manifest[$field] ?? '');
            $this->require($date && $date->format('Y-m-d') === $manifest[$field], 'Invalid edition date: '.$field);
        }
        $this->require(is_string($manifest['retrieved_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $manifest['retrieved_at']), 'Retrieval time requires an explicit timezone.');
        $retrieved = date_parse($manifest['retrieved_at']);
        $this->require($retrieved['error_count'] === 0 && $retrieved['warning_count'] === 0, 'Invalid retrieval time.');
        $this->require(in_array($manifest['source_kind'] ?? null, ['synthetic', 'official_candidate'], true), 'Source kind must be synthetic or official_candidate; authority is not certified by this tool.');
        $files = $manifest['files'] ?? null;
        $this->require(is_array($files) && array_is_list($files) && count($files) >= 1 && count($files) <= 10, 'Original source files are required.');
        $sourceNames = [];
        foreach ($files as $file) {
            $source = $this->artifact(dirname($path), $file);
            $this->require(! isset($sourceNames[$file['path']]), 'Duplicate source file.');
            $sourceNames[$file['path']] = true;
            if ($manifest['source_kind'] === 'official_candidate') {
                $this->workbook($source['path']);
            }
        }
        $records = $this->artifact(dirname($path), $manifest['records'] ?? null);
        $rows = json_decode($records['bytes'], true, 64, JSON_THROW_ON_ERROR);
        $this->require(is_array($rows) && array_is_list($rows) && count($rows) >= 1 && count($rows) <= 100000, 'Expected a bounded nonempty list of normalized places.');
        $places = [];
        $counts = array_fill_keys(self::LEVELS, 0);
        foreach ($rows as $row) {
            $this->require(is_array($row), 'Invalid place row.');
            $code = $row['code'] ?? null;
            $this->require(is_string($code) && preg_match('/^\d{10}$/D', $code) && ! isset($places[$code]), 'Duplicate or invalid canonical identifier.');
            $this->require(in_array($row['level'] ?? null, self::LEVELS, true), 'Unsupported geographic level.');
            $name = is_string($row['name'] ?? null) ? trim(preg_replace('/[\p{Z}\s]+/u', ' ', $row['name'])) : '';
            $this->require($name !== '' && mb_strlen($row['name']) <= 255, 'Invalid geographic name.');
            $this->reference($row['source_ref'] ?? null, $sourceNames);
            foreach (['region_code', 'province_code', 'city_code'] as $parent) {
                $this->require(array_key_exists($parent, $row) && ($row[$parent] === null || (is_string($row[$parent]) && preg_match('/^\d{10}$/D', $row[$parent]))), 'Explicit string or null parent required: '.$parent);
            }
            $correspondences = $row['correspondences'] ?? [];
            $this->require(is_array($correspondences) && array_is_list($correspondences) && count($correspondences) <= 20, 'Invalid correspondence list.');
            $seen = [];
            foreach ($correspondences as $correspondence) {
                $legacy = $correspondence['code'] ?? null;
                $this->require(is_string($legacy) && preg_match('/^\d{9,10}$/D', $legacy) && ! isset($seen[$legacy]), 'Invalid or duplicate correspondence.');
                $this->reference($correspondence['source_ref'] ?? null, $sourceNames);
                $seen[$legacy] = true;
            }
            $places[$code] = ['code' => $code, 'level' => $row['level'], 'name' => $name, 'raw_name' => $row['name'],
                'source_ref' => $row['source_ref'], 'region_code' => $row['region_code'], 'province_code' => $row['province_code'], 'city_code' => $row['city_code'], 'correspondences' => $correspondences];
            $counts[$row['level']]++;
        }
        foreach ($places as $row) {
            $level = $row['level'];
            $this->require(($level === 'region') === ($row['region_code'] === null), 'Only regions may omit a region parent.');
            $this->require(! in_array($level, ['region', 'province'], true) || ($row['province_code'] === null && $row['city_code'] === null), 'Invalid parent level.');
            $this->require(in_array($level, ['region', 'province', 'barangay'], true) || $row['city_code'] === null, 'Cities cannot parent themselves or another city.');
            $this->require($level !== 'barangay' || $row['city_code'] !== null, 'Barangay city/municipality parent is missing.');
            foreach (['region_code' => ['region'], 'province_code' => ['province'], 'city_code' => ['city', 'municipality']] as $field => $levels) {
                if ($row[$field] !== null) {
                    $parent = $places[$row[$field]] ?? null;
                    $this->require($parent && in_array($parent['level'], $levels, true), 'Missing or wrong-level parent: '.$row['code']);
                    if ($field !== 'region_code') {
                        $this->require($parent['region_code'] === $row['region_code'], 'Parent belongs to a different region.');
                    }
                    if ($field === 'city_code') {
                        $this->require($parent['province_code'] === $row['province_code'], 'Barangay province disagrees with its city.');
                    }
                }
            }
        }
        $expected = $manifest['expected_counts'] ?? null;
        $this->require(is_array($expected) && count($expected) === count($counts), 'All five expected level counts are required.');
        foreach ($counts as $level => $count) {
            $this->require(($expected[$level] ?? null) === $count, 'Level count mismatch: '.$level);
        }
        usort($places, fn (array $a, array $b): int => [array_search($a['level'], self::LEVELS), $a['code']] <=> [array_search($b['level'], self::LEVELS), $b['code']]);
        foreach ($places as &$place) {
            usort($place['correspondences'], fn (array $a, array $b): int => $a['code'] <=> $b['code']);
        }
        unset($place);
        $duplicateNames = collect($places)->groupBy(fn (array $place): string => $place['level'].'|'.mb_strtolower($place['name']))
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group): array => $group->map(fn (array $place): array => array_intersect_key($place, array_flip(['code', 'name', 'level', 'region_code', 'province_code', 'city_code'])))->values()->all())->values()->all();
        $normalized = json_encode($places, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return ['manifest' => $manifest, 'manifest_digest' => hash('sha256', $bytes), 'places' => $places,
            'validation' => ['counts' => $counts, 'duplicate_names' => $duplicateNames, 'normalized_sha256' => hash('sha256', $normalized), 'state' => 'validated_staging',
                'authority_verified' => false, 'source_row_reconciliation_verified' => false, 'activation_available' => false]];
    }

    private function artifact(string $root, mixed $file): array
    {
        $this->require(is_array($file) && is_string($file['path'] ?? null) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,150}$/D', $file['path']), 'Artifacts must be plain local filenames.');
        $this->require(is_string($file['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $file['sha256']), 'SHA-256 is required.');
        $path = realpath($root.DIRECTORY_SEPARATOR.$file['path']);
        $this->require($path !== false && dirname($path) === $root && is_file($path), 'Artifact missing or outside package.');
        $bytes = $this->read($path, 33554432);
        $this->require(hash_equals($file['sha256'], hash('sha256', $bytes)), 'Artifact checksum mismatch.');

        return ['path' => $path, 'bytes' => $bytes];
    }

    private function workbook(string $path): void
    {
        $zip = new ZipArchive;
        $this->require($zip->open($path) === true, 'Official candidate source must be an XLSX archive.');
        try {
            $this->require($zip->numFiles <= 1000 && $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false, 'Invalid or oversized XLSX container.');
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $size += $entry['size'];
                $this->require($size <= 268435456 && ! str_contains($entry['name'], '..') && ! str_starts_with($entry['name'], '/') && ! str_contains($entry['name'], '\\'), 'Unsafe or oversized XLSX container.');
            }
        } finally {
            $zip->close();
        }
    }

    private function reference(mixed $reference, array $sources): void
    {
        $this->require(is_string($reference) && mb_strlen($reference) <= 255 && str_contains($reference, '#') && isset($sources[explode('#', $reference, 2)[0]]) && trim(explode('#', $reference, 2)[1]) !== '', 'Missing source row evidence.');
    }

    private function read(string $path, int $limit): string
    {
        $this->require(filesize($path) > 0 && filesize($path) <= $limit, 'Artifact size limit exceeded or file empty.');
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        $this->require(is_string($bytes) && strlen($bytes) <= $limit, 'Could not read bounded artifact.');

        return $bytes;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
