<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            foreach (['first_name', 'surname', 'middle_name', 'suffix', 'contact_number', 'student_number', 'college', 'enrollment_status', 'region_code', 'province_code', 'city_code', 'barangay_code', 'region_name', 'province_name', 'city_name', 'barangay_name', 'street_address', 'sex'] as $column) {
                $table->string($column)->nullable();
            }
            $table->date('date_of_birth')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('requires_email_verification')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('registration_policy_version', 80)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['requires_email_verification', 'terms_accepted_at', 'privacy_accepted_at', 'registration_policy_version']));
        Schema::table('student_profiles', fn (Blueprint $table) => $table->dropColumn(['first_name', 'surname', 'middle_name', 'suffix', 'contact_number', 'student_number', 'college', 'enrollment_status', 'region_code', 'province_code', 'city_code', 'barangay_code', 'region_name', 'province_name', 'city_name', 'barangay_name', 'street_address', 'sex', 'date_of_birth']));
    }
};
