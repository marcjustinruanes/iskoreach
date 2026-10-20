<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedInteger('workflow_version')->default(0);
            $table->json('submission_snapshot')->nullable();
        });
        Schema::create('application_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_histories');
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn(['workflow_version', 'submission_snapshot']));
    }
};
