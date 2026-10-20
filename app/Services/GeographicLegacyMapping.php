<?php

namespace App\Services;

use RuntimeException;

class GeographicLegacyMapping
{
    public const FIELDS = ['id', 'scholarship_id', 'scope', 'region_code', 'city_code', 'region_name', 'city_name', 'created_at', 'updated_at'];

    public function __construct(private GeographicSnapshot $snapshots) {}

    public function report(string $manifest, iterable $coverage): array
    {
        $snapshot = $this->snapshots->inspect($manifest);
        $places = [];
        $index = [];
        foreach ($snapshot['places'] as $place) {
            $places[$place['code']] = $place;
            $index[$place['code']][$place['code']] = ['kind' => 'canonical_identifier', 'source_ref' => $place['source_ref']];
            foreach ($place['correspondences'] as $correspondence) {
                $index[$correspondence['code']][$place['code']] = ['kind' => 'source_correspondence', 'source_ref' => $correspondence['source_ref']];
            }
        }
        $rows = [];
        $seen = [];
        $counts = array_fill_keys(['exact', 'renamed_or_changed', 'moved', 'ambiguous', 'unmatched', 'nationwide'], 0);
        foreach ($coverage as $original) {
            if (count($rows) >= 100000) {
                throw new RuntimeException('Coverage report limit exceeded. Export smaller batches.');
            }
            $old = $this->coverage((array) $original);
            if (isset($seen[$old['id']])) {
                throw new RuntimeException('Coverage IDs must be unique within the report.');
            }
            $seen[$old['id']] = true;
            $result = $this->classify($old, $places, $index);
            $rows[] = ['original' => $old, 'original_fingerprint' => $this->fingerprint($old),
                'target_manifest_digest' => $snapshot['manifest_digest'], 'review_status' => 'unreviewed',
                'authority_verified' => false, 'apply_available' => false, ...$result];
            $counts[$result['classification']]++;
        }
        usort($rows, fn (array $a, array $b): int => $a['original']['id'] <=> $b['original']['id']);

        return ['schema_version' => 1, 'edition' => $snapshot['manifest']['edition'], 'source_kind' => $snapshot['manifest']['source_kind'],
            'target_manifest_digest' => $snapshot['manifest_digest'], 'normalized_sha256' => $snapshot['validation']['normalized_sha256'],
            'authority_verified' => false, 'source_row_reconciliation_verified' => false, 'apply_available' => false,
            'counts' => $counts, 'rows' => $rows,
            'review_instructions' => 'Review official source provenance and each evidence reference. Resolve ambiguous/unmatched records explicitly; retain original coverage. No mapping is applied, including exact proposals. Recheck source and row fingerprints before any separately authorized action.'];
    }

    public function isCurrent(array $proposal, array $coverage, string $manifestDigest): bool
    {
        return is_string($proposal['original_fingerprint'] ?? null) && is_string($proposal['target_manifest_digest'] ?? null)
            && hash_equals($proposal['original_fingerprint'], $this->fingerprint($this->coverage($coverage)))
            && hash_equals($proposal['target_manifest_digest'], $manifestDigest);
    }

    private function classify(array $old, array $places, array $index): array
    {
        $base = ['classification' => 'unmatched', 'flags' => [], 'proposed' => null, 'candidates' => [], 'evidence' => [], 'requires_review' => true];
        if ($old['scope'] === 'nationwide') {
            if ($old['region_code'] !== null || $old['city_code'] !== null) {
                return [...$base, 'classification' => 'ambiguous', 'flags' => ['inconsistent_nationwide_context']];
            }

            return [...$base, 'classification' => 'nationwide', 'requires_review' => false, 'flags' => ['no_identifier_mapping_required']];
        }
        $levels = match ($old['scope']) {
            'region' => ['region'], 'city' => ['city', 'municipality'], default => []
        };
        if ($levels === []) {
            return [...$base, 'flags' => ['unsupported_legacy_scope']];
        }
        $legacyCode = $old[$old['scope'] === 'region' ? 'region_code' : 'city_code'];
        $matches = $this->matches($legacyCode, $levels, $places, $index);
        $candidates = array_map(fn (string $code): array => $this->candidate($places[$code], $index[$legacyCode][$code]), array_keys($matches));
        if (count($matches) !== 1) {
            return [...$base, 'classification' => count($matches) > 1 ? 'ambiguous' : 'unmatched',
                'flags' => [count($matches) > 1 ? 'identifier_collision' : 'no_identifier_evidence'], 'candidates' => $candidates];
        }
        $code = (string) array_key_first($matches);
        $place = $places[$code];
        $evidence = [['legacy_code' => $legacyCode, 'target_code' => $code, ...$index[$legacyCode][$code]]];
        $flags = [];
        if ($old['scope'] === 'city') {
            $regions = $this->matches($old['region_code'], ['region'], $places, $index);
            if (count($regions) !== 1) {
                return [...$base, 'classification' => 'ambiguous', 'flags' => ['legacy_region_unresolved'], 'candidates' => $candidates, 'evidence' => $evidence];
            }
            $regionCode = (string) array_key_first($regions);
            $evidence[] = ['legacy_code' => $old['region_code'], 'target_code' => $regionCode, ...$index[$old['region_code']][$regionCode]];
            if ($regionCode !== $place['region_code']) {
                $flags[] = 'parent_changed';
            }
        }
        $savedName = $old[$old['scope'] === 'region' ? 'region_name' : 'city_name'];
        if ($savedName === null || $this->name($savedName) === '') {
            return [...$base, 'classification' => 'ambiguous', 'flags' => [...$flags, 'saved_label_missing'], 'candidates' => $candidates, 'evidence' => $evidence];
        }
        if ($this->name($savedName) !== $this->name($place['name'])) {
            $flags[] = 'saved_label_differs';
        }
        if ($old['scope'] === 'city' && $old['region_name'] !== null && $this->name($old['region_name']) !== $this->name($places[$regionCode]['name'])) {
            $flags[] = 'saved_region_label_differs';
        }
        $classification = in_array('parent_changed', $flags, true) ? 'moved' : ($flags === [] ? 'exact' : 'renamed_or_changed');

        return [...$base, 'classification' => $classification, 'flags' => $flags, 'proposed' => $this->candidate($place, $index[$legacyCode][$code]),
            'candidates' => $candidates, 'evidence' => $evidence];
    }

    private function matches(?string $code, array $levels, array $places, array $index): array
    {
        return array_filter($index[$code ?? ''] ?? [], fn (array $evidence, string $target): bool => in_array($places[$target]['level'], $levels, true), ARRAY_FILTER_USE_BOTH);
    }

    private function candidate(array $place, array $evidence): array
    {
        return ['code' => $place['code'], 'name' => $place['name'], 'level' => $place['level'],
            'region_code' => $place['region_code'], 'province_code' => $place['province_code'], 'city_code' => $place['city_code'], 'evidence' => $evidence];
    }

    private function name(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $name)));
    }

    private function coverage(array $row): array
    {
        $old = array_fill_keys(self::FIELDS, null);
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, ['id', 'scholarship_id'], true)) {
                if (! is_int($value) || $value < 1) {
                    throw new RuntimeException('Coverage IDs must be positive integers.');
                }
            } elseif ($value !== null && (! is_string($value) || mb_strlen($value) > 255)) {
                throw new RuntimeException('Invalid legacy coverage field: '.$field);
            }
            $old[$field] = $value;
        }
        if (! is_string($old['scope']) || trim($old['scope']) === '') {
            throw new RuntimeException('Coverage scope is required.');
        }

        return $old;
    }

    private function fingerprint(array $coverage): string
    {
        return hash('sha256', json_encode($coverage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
