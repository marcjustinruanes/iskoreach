<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_awards', function (Blueprint $table): void {
            $table->unsignedInteger('workflow_version')->default(0);
            $table->json('history')->nullable();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedInteger('workflow_version')->default(0);
            $table->json('history')->nullable();
            $table->uuid('submission_token')->nullable();
            $table->string('creation_hash', 64)->nullable();
            $table->unique(['scholarship_award_id', 'submission_token'], 'release_retry_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('release_retry_unique');
            $table->dropColumn(['workflow_version', 'history', 'submission_token', 'creation_hash']);
        });
        Schema::table('scholarship_awards', fn (Blueprint $table) => $table->dropColumn(['workflow_version', 'history']));
    }
};
