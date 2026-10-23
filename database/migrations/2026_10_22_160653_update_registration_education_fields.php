<?php

use App\Enums\EducationLevel;
use App\Enums\NameSuffix;
use App\Enums\Sex;
use App\Enums\YearLevel;
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
        foreach (['student_profiles' => ['suffix' => array_column(NameSuffix::cases(), 'value'), 'sex' => array_column(Sex::cases(), 'value'), 'year_level' => array_column(YearLevel::cases(), 'value')], 'provider_profiles' => ['suffix' => array_column(NameSuffix::cases(), 'value')]] as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                if (DB::table($table)->whereNotNull($column)->whereNotIn($column, $allowed)->exists()) {
                    throw new RuntimeException("Resolve unsupported {$table}.{$column} values before applying the registration enums.");
                }
            }
        }

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('education_level', array_column(EducationLevel::cases(), 'value'));
            $table->timestamps();
            $table->unique(['name', 'education_level']);
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->enum('education_level', array_column(EducationLevel::cases(), 'value'))->nullable();
        });
        DB::table('student_profiles')->whereIn('year_level', [11, 12])->update(['education_level' => EducationLevel::SeniorHigh->value]);
        DB::table('student_profiles')->whereBetween('year_level', [1, 6])->update(['education_level' => EducationLevel::College->value]);

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->enum('suffix', array_column(NameSuffix::cases(), 'value'))->nullable()->change();
            $table->enum('sex', array_column(Sex::cases(), 'value'))->nullable()->change();
            $table->enum('year_level', array_map(fn (YearLevel $year): string => (string) $year->value, YearLevel::cases()))->nullable()->change();
            $table->dropColumn('college');
        });

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->enum('suffix', array_column(NameSuffix::cases(), 'value'))->nullable()->change();
        });

        DB::table('student_profiles')->whereNotNull('course')->whereNotNull('education_level')->select('course', 'education_level')->distinct()->get()
            ->each(function (object $profile): void {
                if (trim($profile->course) !== '') {
                    DB::table('courses')->insertOrIgnore(['name' => $profile->course, 'education_level' => $profile->education_level, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->string('suffix')->nullable()->change();
        });
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('college')->nullable();
            $table->string('suffix')->nullable()->change();
            $table->string('sex')->nullable()->change();
            $table->unsignedTinyInteger('year_level')->nullable()->change();
            $table->dropColumn('education_level');
        });
        Schema::dropIfExists('courses');
    }
};
