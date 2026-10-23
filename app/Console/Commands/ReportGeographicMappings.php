<?php

namespace App\Console\Commands;

use App\Services\GeographicLegacyMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReportGeographicMappings extends Command
{
    protected $signature = 'geography:mapping-report {manifest} {--coverage= : Local JSON array of legacy coverage} {--current : Explicitly read current configured scholarship-location rows}';

    protected $description = 'Print read-only legacy mapping proposals; never updates records or activates a dataset';

    public function handle(GeographicLegacyMapping $mapping): int
    {
        try {
            $file = $this->option('coverage');
            if ((bool) $file === (bool) $this->option('current')) {
                throw new RuntimeException('Choose exactly one of --coverage=FILE or --current.');
            }
            if ($file) {
                if (! is_file($file) || filesize($file) > 33554432) {
                    throw new RuntimeException('Coverage JSON must be an existing bounded local file.');
                }
                $rows = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($rows) || ! array_is_list($rows)) {
                    throw new RuntimeException('Coverage JSON must contain a list.');
                }
            } else {
                $rows = DB::table('scholarship_locations')->select(['id', 'scholarship_id', 'scope', 'region_name', 'city_name', 'created_at', 'updated_at'])->selectRaw('NULL AS region_code, NULL AS city_code')->orderBy('id')->cursor();
            }
            $this->line(json_encode($mapping->report($this->argument('manifest'), $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
