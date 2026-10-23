<?php

use App\Services\PhilippineLocations;
use App\Services\StudentRegistration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            $backup = [];
            foreach (['student_profiles', 'provider_profiles', 'scholarship_locations', 'applications'] as $table) {
                $backup[$table] = DB::table($table)->get()->all();
            }
            if (! Storage::disk('local')->put('backups/location-names-'.now()->format('Ymd-His').'.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not back up existing locations before converting the database.');
            }
        }
        foreach (['campus_region_name', 'campus_province_name', 'campus_city_name'] as $column) {
            if (! Schema::hasColumn('student_profiles', $column)) {
                Schema::table('student_profiles', fn (Blueprint $table) => $table->string($column)->nullable());
            }
        }
        $updates = [];
        foreach (['student_profiles', 'provider_profiles', 'scholarship_locations'] as $table) {
            foreach (DB::table($table)->orderBy('id')->get() as $record) {
                $row = (array) $record;
                $names = [];
                if ($table === 'scholarship_locations') {
                    if (($row['scope'] ?? '') !== 'nationwide' && filled($row['region_code'] ?? null)) {
                        $names = app(PhilippineLocations::class)->resolve(['scope' => $row['scope'], 'region_name' => $row['region_code'], 'province_name' => $row['province_code'] ?? null, 'city_name' => $row['city_code'] ?? null]);
                    }
                } else {
                    foreach (['region', 'province', 'city', 'barangay'] as $level) {
                        if (filled($row[$level.'_code'] ?? null)) {
                            $address = [];
                            foreach (['region', 'province', 'city', 'barangay'] as $part) {
                                $address[$part.'_name'] = $row[$part.'_code'] ?? $row[$part.'_name'] ?? null;
                            }
                            if (! filled($address['province_name'])) {
                                $region = app(PhilippineLocations::class)->choice(app(PhilippineLocations::class)->regions()['data'], $address['region_name']);
                                $city = app(PhilippineLocations::class)->choice(app(PhilippineLocations::class)->cities($region['code'])['data'], $address['city_name']);
                                $address['province_name'] = $city['province_code'] ?? PhilippineLocations::NO_PROVINCE;
                            }
                            $names = app(StudentRegistration::class)->address($address, filled($row['barangay_code'] ?? null));
                            break;
                        }
                    }
                    if ($table === 'student_profiles' && filled($row['campus_region_code'] ?? null) && filled($row['campus_city_code'] ?? null)) {
                        $names = [...$names, ...app(StudentRegistration::class)->campus(['campus_region_name' => $row['campus_region_code'], 'campus_province_name' => $row['campus_province_code'] ?? 'none', 'campus_city_name' => $row['campus_city_code']])];
                    }
                    if ($table === 'provider_profiles' && filled($row['coverage_areas'] ?? null)) {
                        $areas = json_decode($row['coverage_areas'], true, flags: JSON_THROW_ON_ERROR);
                        $names['coverage_areas'] = json_encode(array_map(fn (array $area): array => ['scope' => $area['scope'], ...app(PhilippineLocations::class)->resolve($area)], $areas), JSON_THROW_ON_ERROR);
                    }
                }
                if ($names !== []) {
                    $updates[] = [$table, $row['id'], $names];
                }
            }
        }
        $snapshots = [];
        foreach (DB::table('applications')->whereNotNull('submission_snapshot')->get(['id', 'submission_snapshot']) as $row) {
            $snapshot = json_decode($row->submission_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $student = $snapshot['student'] ?? [];
            if (filled($student['campus_region_code'] ?? null) && filled($student['campus_city_code'] ?? null)) {
                $student = [...$student, ...app(StudentRegistration::class)->campus(['campus_region_name' => $student['campus_region_code'], 'campus_province_name' => $student['campus_province_code'] ?? 'none', 'campus_city_name' => $student['campus_city_code']])];
            }
            foreach (['campus_region_code', 'campus_province_code', 'campus_city_code'] as $key) {
                unset($student[$key]);
            }
            if (isset($snapshot['student'])) {
                $snapshot['student'] = $student;
            }
            $snapshots[] = [$row->id, json_encode($snapshot, JSON_THROW_ON_ERROR)];
        }
        DB::transaction(function () use ($updates, $snapshots): void {
            foreach ($updates as [$table, $id, $names]) {
                DB::table($table)->where('id', $id)->update($names);
            }
            foreach ($snapshots as [$id, $snapshot]) {
                DB::table('applications')->where('id', $id)->update(['submission_snapshot' => $snapshot]);
            }
        });
        foreach (['student_profiles', 'provider_profiles', 'scholarship_locations'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary'] && array_intersect($index['columns'], ['region_code', 'province_code', 'city_code', 'barangay_code'])) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }
            $columns = array_values(array_intersect(Schema::getColumnListing($table), ['region_code', 'province_code', 'city_code', 'barangay_code', 'campus_region_code', 'campus_province_code', 'campus_city_code']));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Location codes cannot be restored from names without a verified API conversion. Restore a database backup to reverse this migration.');
    }
};
