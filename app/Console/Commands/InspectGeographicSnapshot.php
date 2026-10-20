<?php

namespace App\Console\Commands;

use App\Services\GeographicSnapshot;
use Illuminate\Console\Command;
use Throwable;

class InspectGeographicSnapshot extends Command
{
    protected $signature = 'geography:inspect {manifest : Local normalized-package manifest JSON}';

    protected $description = 'Read-only integrity validation; never imports or activates a geographic source';

    public function handle(GeographicSnapshot $snapshots): int
    {
        try {
            $snapshot = $snapshots->inspect($this->argument('manifest'));
            $this->line(json_encode(['manifest_digest' => $snapshot['manifest_digest'], ...$snapshot['validation']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
