<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            Schema::table('provider_profiles', function (Blueprint $table): void {
                $table->json('scholarship_eligibility')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            Schema::table('provider_profiles', function (Blueprint $table): void {
                $table->dropColumn('scholarship_eligibility');
            });
        });
    }
};
