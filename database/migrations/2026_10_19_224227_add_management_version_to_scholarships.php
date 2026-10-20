<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->unsignedInteger('management_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('management_version');
        });
    }
};
