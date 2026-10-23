<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('scholarship_awards', 'scholarship_grants');
        Schema::create('document_submissions', function (Blueprint $table): void {
            $table->id();
            $table->enum('submission_type', ['new_application', 'renewal']);
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('renewal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('scholarship_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('requirement_index')->nullable();
            $table->foreignId('student_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('requirement_snapshot')->nullable();
            $table->timestamp('checklist_completed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'scholarship_requirement_id', 'version'], 'submission_application_requirement_version');
            $table->unique(['renewal_id', 'requirement_index', 'version'], 'submission_renewal_requirement_version');
        });
        DB::table('application_documents')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('document_submissions')->insert((array) $row + ['submission_type' => 'new_application']);
            }
        });
        $offset = (int) DB::table('document_submissions')->max('id');
        DB::table('renewal_documents')->orderBy('id')->chunkById(200, function ($rows) use ($offset): void {
            foreach ($rows as $row) {
                $data = (array) $row;
                $data['id'] += $offset;
                DB::table('document_submissions')->insert($data + ['submission_type' => 'renewal']);
            }
        });
        Schema::drop('application_documents');
        Schema::drop('renewal_documents');
    }

    public function down(): void
    {
        Schema::create('application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('scholarship_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('requirement_snapshot');
            $table->timestamp('checklist_completed_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'scholarship_requirement_id', 'version'], 'application_requirement_document_version');
        });
        Schema::create('renewal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('renewal_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('requirement_index');
            $table->foreignId('student_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['renewal_id', 'requirement_index', 'version'], 'renewal_document_version_unique');
        });
        DB::table('document_submissions')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $data = (array) $row;
                $renewal = $data['submission_type'] === 'renewal';
                $columns = Schema::getColumnListing($renewal ? 'renewal_documents' : 'application_documents');
                DB::table($renewal ? 'renewal_documents' : 'application_documents')->insert(array_intersect_key($data, array_flip($columns)));
            }
        });
        Schema::drop('document_submissions');
        Schema::rename('scholarship_grants', 'scholarship_awards');
    }
};
