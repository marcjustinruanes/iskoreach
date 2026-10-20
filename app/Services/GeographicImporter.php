<?php

namespace App\Services;

use Illuminate\Database\Connection;
use RuntimeException;

class GeographicImporter
{
    public function __construct(private GeographicSnapshot $snapshots) {}

    public function stagingPath(string $path): string
    {
        $root = realpath(storage_path('app/private'));
        $parent = realpath(dirname($path));
        if ($root === false || $parent !== $root || ! preg_match('/^geographic-staging-[a-zA-Z0-9_-]+\.sqlite$/D', basename($path))) {
            throw new RuntimeException('Use an explicit geographic-staging-NAME.sqlite file directly inside storage/app/private.');
        }
        $resolved = $parent.DIRECTORY_SEPARATOR.basename($path);
        if (file_exists($resolved) && realpath($resolved) !== $resolved) {
            throw new RuntimeException('Staging database symlinks are not allowed.');
        }

        return $resolved;
    }

    public function stage(string $manifest, Connection $connection): array
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Staging imports require isolated SQLite; configured MySQL is never a staging target.');
        }
        if (! (app()->environment('testing') && $connection->getDatabaseName() === ':memory:')) {
            $this->stagingPath($connection->getDatabaseName());
        }
        $snapshot = $this->snapshots->inspect($manifest);

        return $connection->transaction(function () use ($connection, $snapshot): array {
            $edition = $snapshot['manifest']['edition'];
            $existing = $connection->table('geographic_datasets')->where('edition', $edition)->first();
            if ($existing) {
                if (! hash_equals($existing->manifest_digest, $snapshot['manifest_digest'])) {
                    throw new RuntimeException('This edition already exists with different manifest bytes. Use an explicitly reviewed revision label.');
                }

                return ['dataset_id' => $existing->id, 'created' => false, 'validation' => $snapshot['validation']];
            }
            $id = $connection->table('geographic_datasets')->insertGetId(['edition' => $edition, 'manifest_digest' => $snapshot['manifest_digest'],
                'source_kind' => $snapshot['manifest']['source_kind'], 'state' => 'validated_staging',
                'manifest' => json_encode($snapshot['manifest'], JSON_THROW_ON_ERROR), 'validation' => json_encode($snapshot['validation'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($snapshot['places'], 200) as $chunk) {
                $connection->table('geographic_places')->insert(array_map(function (array $place) use ($id): array {
                    unset($place['correspondences']);

                    return ['dataset_id' => $id, ...$place];
                }, $chunk));
            }
            $correspondences = [];
            foreach ($snapshot['places'] as $place) {
                foreach ($place['correspondences'] as $correspondence) {
                    $correspondences[] = ['dataset_id' => $id, 'place_code' => $place['code'], 'legacy_code' => $correspondence['code'], 'source_ref' => $correspondence['source_ref']];
                }
            }
            foreach (array_chunk($correspondences, 200) as $chunk) {
                $connection->table('geographic_correspondences')->insert($chunk);
            }

            return ['dataset_id' => $id, 'created' => true, 'validation' => $snapshot['validation']];
        });
    }
}
