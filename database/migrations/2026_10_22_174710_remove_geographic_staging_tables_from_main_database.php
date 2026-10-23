<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['geographic_correspondences', 'geographic_places', 'geographic_datasets'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Export geographic staging records to an isolated staging database before removing its main database tables.');
            }
        }
        Schema::dropIfExists('geographic_correspondences');
        Schema::dropIfExists('geographic_places');
        Schema::dropIfExists('geographic_datasets');
    }

    public function down(): void
    {
        $migration = require database_path('migrations/2026_10_20_134208_create_geographic_directory_tables.php');
        $migration->up();
    }
};
