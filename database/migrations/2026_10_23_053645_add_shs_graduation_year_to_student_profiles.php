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
        Schema::table('student_profiles', function (Blueprint $table) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->unsignedSmallInteger('graduation_year')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->dropColumn('graduation_year');
            });
        });
    }
};
