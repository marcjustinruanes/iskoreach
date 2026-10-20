<?php

namespace App\Console\Commands;

use App\Services\GeographicImporter;
use App\Services\GeographicSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StageGeographicSnapshot extends Command
{
    protected $signature = 'geography:stage {manifest} {--database= : Explicit isolated SQLite file} {--initialize : Create a NEW isolated staging file and apply only geographic tables}';

    protected $description = 'Import a normalized package into isolated SQLite only; no source activation or scholarship mapping';

    public function handle(GeographicImporter $importer, GeographicSnapshot $snapshots): int
    {
        $previous = config('database.connections.geographic_staging');
        try {
            if (! is_string($this->option('database')) || $this->option('database') === '') {
                throw new RuntimeException('An explicit isolated --database path is required.');
            }
            $path = $importer->stagingPath($this->option('database'));
            $snapshots->inspect($this->argument('manifest'));
            if ($this->option('initialize')) {
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Initialization requires a new staging file; existing files are never overwritten.');
                }
                fclose($handle);
            } elseif (! is_file($path)) {
                throw new RuntimeException('Initialize a new isolated staging file explicitly first.');
            }
            config(['database.connections.geographic_staging' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge('geographic_staging');
            if ($this->option('initialize')) {
                $migrations = glob(database_path('migrations/*_create_geographic_directory_tables.php'));
                if (count($migrations) !== 1 || Artisan::call('migrate', ['--database' => 'geographic_staging', '--path' => $migrations[0], '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== 0) {
                    throw new RuntimeException('Isolated geographic staging schema initialization failed.');
                }
            }
            $this->line(json_encode($importer->stage($this->argument('manifest'), DB::connection('geographic_staging')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge('geographic_staging');
            config(['database.connections.geographic_staging' => $previous]);
        }
    }
}
