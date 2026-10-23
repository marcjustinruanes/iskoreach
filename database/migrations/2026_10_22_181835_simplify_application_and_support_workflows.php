<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('queue.default') !== 'sync' || config('auth.passwords.accounts.driver') !== 'cache') {
            throw new RuntimeException('Select synchronous notifications and cache-backed password recovery before removing their tables.');
        }
        if (DB::table('jobs')->exists()) {
            throw new RuntimeException('Finish pending notification jobs before removing the queue tables.');
        }
        if (DB::table('external_applicant_counts')->exists()) {
            throw new RuntimeException('Export the old aggregate reports before replacing them with individual applicant records.');
        }
        Schema::table('applications', function (Blueprint $table): void {
            $table->foreignId('student_id')->nullable()->change();
            $table->enum('applicant_source', ['internal', 'external'])->default('internal');
            $table->string('applicant_name')->nullable();
            $table->string('applicant_email')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->dropColumn('declaration_snapshot');
        });
        DB::table('applications')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $account = DB::table('accounts')->where('id', $row->student_id)->first();
                DB::table('applications')->where('id', $row->id)->update(['applicant_name' => $account->name, 'applicant_email' => strtolower($account->email)]);
            }
        });
        Schema::table('applications', fn (Blueprint $table) => $table->unique(['scholarship_id', 'applicant_email'], 'application_applicant_email_unique'));
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn('require_verified_declarations'));
        Schema::table('student_profiles', fn (Blueprint $table) => $table->dropColumn('verification_documents'));
        Schema::drop('application_status_histories');
        Schema::drop('scholarship_declaration_reviews');
        Schema::drop('scholarship_declarations');
        Schema::drop('student_verifications');
        Schema::drop('external_applicant_counts');
        Schema::drop('password_reset_tokens');
        Schema::drop('jobs');
        Schema::drop('failed_jobs');
        DB::table('notifications')->where('type', 'student_verification')->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the database backup to recover removed workflows and their data. This migration deliberately removes those features.');
    }
};
