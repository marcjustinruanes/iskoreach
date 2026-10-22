<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->text('organization_description')->nullable();
            $table->string('country', 2)->default('PH');
            $table->string('barangay_code', 32)->nullable();
            $table->string('barangay_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['organization_description', 'country', 'barangay_code', 'barangay_name']);
        });
    }
};
