<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roles = array_column(UserRole::cases(), 'value');
        if (DB::table('users')->whereNull('role')->orWhereNotIn('role', $roles)->exists()) {
            throw new RuntimeException('Resolve unsupported user roles before applying the role enum.');
        }

        Schema::table('users', function (Blueprint $table) use ($roles): void {
            $table->enum('role', $roles)->default('student')->change();
            $table->dropColumn([
                'email_verified_at', 'remember_token', 'requires_email_verification',
                'terms_accepted_at', 'privacy_accepted_at', 'email_code_hash',
                'email_code_expires_at', 'email_code_sent_at', 'email_code_attempts',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('student')->change();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->boolean('requires_email_verification')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('email_code_hash')->nullable();
            $table->timestamp('email_code_expires_at')->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->unsignedTinyInteger('email_code_attempts')->default(0);
        });
    }
};
