<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('campus')->nullable();
        });
        Schema::table('provider_profiles', function (Blueprint $table): void {
            foreach (['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'organization_type', 'office_address', 'region_code', 'province_code', 'city_code', 'region_name', 'province_name', 'city_name', 'organization_phone', 'website'] as $field) {
                $table->string($field)->nullable();
            }
            $table->unsignedTinyInteger('registration_version')->default(0);
            $table->boolean('authorization_required')->default(false);
            $table->json('verification_documents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', fn (Blueprint $table) => $table->dropColumn('campus'));
        Schema::table('provider_profiles', fn (Blueprint $table) => $table->dropColumn(['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'organization_type', 'office_address', 'region_code', 'province_code', 'city_code', 'region_name', 'province_name', 'city_name', 'organization_phone', 'website', 'registration_version', 'authorization_required', 'verification_documents']));
    }
};
