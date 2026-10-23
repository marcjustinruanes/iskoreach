<?php

use App\Enums\OrganizationType;
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
        if (DB::table('provider_profiles')->whereNotNull('organization_type')->whereNotIn('organization_type', array_column(OrganizationType::cases(), 'value'))->exists()) {
            throw new RuntimeException('Resolve unsupported provider organization types before applying the enum.');
        }
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn('enrollment_status');
        });
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->enum('organization_type', array_column(OrganizationType::cases(), 'value'))->nullable()->change();
        });
        Schema::create('policy_documents', function (Blueprint $table): void {
            $table->id();
            $table->enum('kind', ['terms', 'privacy']);
            $table->string('title', 200);
            $table->text('content');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'published_at', 'id']);
        });
        foreach (require config_path('registration-policies.php') as $kind => $policy) {
            DB::table('policy_documents')->insert(['kind' => $kind, ...$policy, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('terms_policy_id')->nullable()->constrained('policy_documents')->restrictOnDelete();
            $table->foreignId('privacy_policy_id')->nullable()->constrained('policy_documents')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('terms_policy_id');
            $table->dropConstrainedForeignId('privacy_policy_id');
        });
        Schema::dropIfExists('policy_documents');
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->string('organization_type')->nullable()->change();
        });
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('enrollment_status')->nullable();
        });
    }
};
