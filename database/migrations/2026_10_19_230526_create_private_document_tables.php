<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->string('label', 120);
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'created_at']);
        });
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
            $table->timestamps();
            $table->unique(['application_id', 'scholarship_requirement_id', 'version'], 'application_requirement_document_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('student_documents');
    }
};
