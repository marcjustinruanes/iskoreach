<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_locations', function (Blueprint $table): void {
            $table->string('region_name')->nullable();
            $table->string('city_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_locations', fn (Blueprint $table) => $table->dropColumn(['region_name', 'city_name']));
    }
};
