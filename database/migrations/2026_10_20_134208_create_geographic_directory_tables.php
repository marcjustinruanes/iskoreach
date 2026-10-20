<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geographic_datasets', function (Blueprint $table): void {
            $table->id();
            $table->string('edition', 160)->unique();
            $table->string('manifest_digest', 64)->unique();
            $table->string('source_kind', 32);
            $table->string('state', 32);
            $table->json('manifest');
            $table->json('validation');
            $table->timestamps();
        });
        Schema::create('geographic_places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_id')->constrained('geographic_datasets')->restrictOnDelete();
            $table->string('code', 10);
            $table->string('level', 16);
            $table->string('name');
            $table->string('raw_name');
            $table->string('source_ref');
            $table->string('region_code', 10)->nullable();
            $table->string('province_code', 10)->nullable();
            $table->string('city_code', 10)->nullable();
            $table->unique(['dataset_id', 'code']);
            foreach (['region_code', 'province_code', 'city_code'] as $parent) {
                $table->foreign(['dataset_id', $parent])->references(['dataset_id', 'code'])->on('geographic_places')->restrictOnDelete();
                $table->index(['dataset_id', $parent, 'level']);
            }
        });
        Schema::create('geographic_correspondences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dataset_id');
            $table->string('place_code', 10);
            $table->string('legacy_code', 10);
            $table->string('source_ref');
            $table->foreign(['dataset_id', 'place_code'])->references(['dataset_id', 'code'])->on('geographic_places')->restrictOnDelete();
            $table->unique(['dataset_id', 'legacy_code', 'place_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geographic_correspondences');
        Schema::dropIfExists('geographic_places');
        Schema::dropIfExists('geographic_datasets');
    }
};
