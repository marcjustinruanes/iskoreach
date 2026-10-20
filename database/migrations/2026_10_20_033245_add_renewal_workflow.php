<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewals', function (Blueprint $table): void {
            $table->unsignedInteger('workflow_version')->default(0);
            $table->json('requirements_snapshot')->nullable();
            $table->json('history')->nullable();
            $table->json('reminder_days')->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_documents');
        Schema::table('renewals', fn (Blueprint $table) => $table->dropColumn(['workflow_version', 'requirements_snapshot', 'history', 'reminder_days']));
    }
};
