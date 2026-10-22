<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('amount', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNull('amount')->exists()) {
            throw new RuntimeException('Cannot make amounts required while unknown or non-cash benefit records exist.');
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('amount', 12, 2)->nullable(false)->change();
        });
    }
};
