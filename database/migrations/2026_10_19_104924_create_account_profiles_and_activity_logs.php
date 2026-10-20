<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('school')->nullable();
            $table->string('course')->nullable();
            $table->unsignedTinyInteger('year_level')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('organization_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('verification_status')->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['actor_id', 'occurred_at']);
        });

        // Preserve existing accounts and create only their missing profile records.
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $profile = ['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];
                if ($user->role === 'student') {
                    DB::table('student_profiles')->insert($profile);
                } elseif ($user->role === 'provider') {
                    DB::table('provider_profiles')->insert([...$profile, 'contact_email' => $user->email]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('provider_profiles');
        Schema::dropIfExists('student_profiles');
    }
};
