<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (! app()->runningUnitTests()) {
                if (! Storage::disk('local')->put('backups/remove-campus-'.now()->format('Ymd-His').'.json', json_encode([
                    'profiles' => DB::table('student_profiles')->get(['id', 'campus'])->all(),
                    'applications' => DB::table('applications')->whereNotNull('submission_snapshot')->get(['id', 'submission_snapshot'])->all(),
                ], JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Could not back up campus details before removing the column.');
                }
            }
            foreach (DB::table('applications')->whereNotNull('submission_snapshot')->get(['id', 'submission_snapshot']) as $row) {
                $snapshot = json_decode($row->submission_snapshot, true, flags: JSON_THROW_ON_ERROR);
                unset($snapshot['student']['campus']);
                DB::table('applications')->where('id', $row->id)->update(['submission_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            }
            Schema::table('student_profiles', fn (Blueprint $table) => $table->dropColumn('campus'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            Schema::table('student_profiles', fn (Blueprint $table) => $table->string('campus')->nullable());
        });
    }
};
