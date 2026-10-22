<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('photo_path')->nullable();
            $table->json('verification_documents')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_code_hash')->nullable();
            $table->timestamp('email_code_expires_at')->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->unsignedTinyInteger('email_code_attempts')->default(0);
        });
        Schema::create('student_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status')->default('pending');
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_verifications');
        Schema::table('student_profiles', fn (Blueprint $table) => $table->dropColumn(['photo_path', 'verification_documents']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['email_code_hash', 'email_code_expires_at', 'email_code_sent_at', 'email_code_attempts']));
    }
};
