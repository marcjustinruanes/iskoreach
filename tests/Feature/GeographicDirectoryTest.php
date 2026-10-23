<?php

namespace Tests\Feature;

use App\Services\GeographicDirectory;
use App\Services\GeographicImporter;
use App\Services\GeographicSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeographicDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $package;

    private array $rows;

    private array $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_20_134208_create_geographic_directory_tables.php'))->up();
        Http::preventStrayRequests();
        $this->package = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iskoreach-geographic-'.bin2hex(random_bytes(8));
        mkdir($this->package);
        file_put_contents($this->package.'/source.txt', 'Synthetic test evidence; not an official geographic dataset.');
        $this->rows = [
            $this->place('9900000000', 'region', null, null, null, 'Synthetic Region'),
            $this->place('9900100000', 'province', '9900000000', null, null, 'Synthetic Province'),
            $this->place('9900101000', 'city', '9900000000', '9900100000', null, 'Repeated Place'),
            $this->place('9900201000', 'city', '9900000000', null, null, 'Repeated Place'),
            $this->place('9900102000', 'municipality', '9900000000', '9900100000', null, 'Synthetic Municipality'),
            $this->place('9900101001', 'barangay', '9900000000', '9900100000', '9900101000', 'Synthetic Barangay'),
        ];
        $this->rows[2]['correspondences'] = [['code' => '990010100', 'source_ref' => 'source.txt#row:3']];
        $this->manifest = ['schema_version' => 1, 'edition' => 'synthetic-phase3', 'publisher' => 'Isolated tests',
            'source_kind' => 'synthetic', 'source_url' => 'https://example.test/geography', 'terms_url' => 'https://example.test/terms',
            'attribution' => 'Synthetic test fixtures', 'transformation' => 'Explicit fixture rows', 'parser_version' => 'fixture-v1',
            'as_of' => '2026-09-30', 'released_on' => '2026-10-12', 'retrieved_at' => '2026-10-20T12:00:00Z',
            'expected_counts' => ['region' => 1, 'province' => 1, 'city' => 2, 'municipality' => 1, 'barangay' => 1]];
        $this->savePackage();
    }

    protected function tearDown(): void
    {
        foreach (['manifest.json', 'places.json', 'source.txt'] as $file) {
            if (is_file($this->package.'/'.$file)) {
                unlink($this->package.'/'.$file);
            }
        }
        rmdir($this->package);
        parent::tearDown();
    }

    private function place(string $code, string $level, ?string $region, ?string $province, ?string $city, string $name): array
    {
        return ['code' => $code, 'level' => $level, 'name' => $name, 'region_code' => $region, 'province_code' => $province,
            'city_code' => $city, 'source_ref' => 'source.txt#fixture:'.$code, 'correspondences' => []];
    }

    private function savePackage(): string
    {
        file_put_contents($this->package.'/places.json', json_encode($this->rows, JSON_THROW_ON_ERROR));
        $this->manifest['files'] = [['path' => 'source.txt', 'sha256' => hash_file('sha256', $this->package.'/source.txt')]];
        $this->manifest['records'] = ['path' => 'places.json', 'sha256' => hash_file('sha256', $this->package.'/places.json')];
        file_put_contents($this->package.'/manifest.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));

        return $this->package.'/manifest.json';
    }

    private function stage(): array
    {
        return app(GeographicImporter::class)->stage($this->savePackage(), DB::connection());
    }

    private function rejected(callable $mutation, string $message): void
    {
        $mutation();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        app(GeographicSnapshot::class)->inspect($this->savePackage());
    }

    public function test_inspection_is_read_only_and_reproducible_without_certifying_authority(): void
    {
        $before = DB::table('geographic_datasets')->count();
        $one = app(GeographicSnapshot::class)->inspect($this->savePackage());
        $this->rows = array_reverse($this->rows);
        $two = app(GeographicSnapshot::class)->inspect($this->savePackage());
        $this->assertSame($one['validation']['normalized_sha256'], $two['validation']['normalized_sha256']);
        $this->assertFalse($one['validation']['authority_verified']);
        $this->assertCount(1, $one['validation']['duplicate_names']);
        $this->assertFalse($one['validation']['activation_available']);
        $this->assertSame($before, DB::table('geographic_datasets')->count());
        $this->artisan('geography:inspect', ['manifest' => $this->package.'/manifest.json'])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_staging_is_idempotent_preserves_strings_and_does_not_change_scholarship_coverage(): void
    {
        $one = $this->stage();
        $two = $this->stage();
        $this->assertTrue($one['created']);
        $this->assertFalse($two['created']);
        $this->assertSame($one['dataset_id'], $two['dataset_id']);
        $this->assertDatabaseCount('geographic_places', 6);
        $this->assertDatabaseCount('scholarship_locations', 0);
        $this->assertDatabaseHas('geographic_datasets', ['state' => 'validated_staging', 'source_kind' => 'synthetic']);
        $this->assertDatabaseHas('geographic_correspondences', ['legacy_code' => '990010100', 'place_code' => '9900101000']);
        $this->rows[0]['code'] = '0100000000';
        foreach ($this->rows as &$row) {
            if ($row['region_code'] !== null) {
                $row['region_code'] = '0100000000';
            }
        }
        unset($row);
        $this->manifest['edition'] = 'another-synthetic-edition';
        $this->stage();
        $this->assertDatabaseHas('geographic_places', ['code' => '0100000000']);
    }

    public function test_directory_queries_are_edition_scoped_and_do_not_invent_province_membership(): void
    {
        $first = $this->stage()['dataset_id'];
        $directory = app(GeographicDirectory::class);
        $this->assertCount(2, $directory->options(DB::connection(), $first, 'city', '9900000000')['places']);
        $this->assertCount(1, $directory->options(DB::connection(), $first, 'city', '9900100000')['places']);
        $this->manifest['edition'] = 'second';
        $this->rows[2]['name'] = 'Different edition name';
        $second = $this->stage()['dataset_id'];
        $this->assertSame('Repeated Place', $directory->options(DB::connection(), $first, 'city', '9900100000')['places'][0]['name']);
        $this->assertSame('Different edition name', $directory->options(DB::connection(), $second, 'city', '9900100000')['places'][0]['name']);
        $this->expectException(\InvalidArgumentException::class);
        $directory->options(DB::connection(), $first, 'city', '9999999999');
    }

    public function test_correspondence_collisions_return_candidates_without_automatic_mapping(): void
    {
        $this->rows[3]['correspondences'] = $this->rows[2]['correspondences'];
        $id = $this->stage()['dataset_id'];
        $candidates = app(GeographicDirectory::class)->correspondenceCandidates(DB::connection(), $id, '990010100');
        $this->assertCount(2, $candidates);
        $this->assertDatabaseCount('scholarship_locations', 0);
    }

    public function test_duplicate_identifiers_fail_but_duplicate_names_are_allowed(): void
    {
        $this->rejected(function (): void {
            $this->rows[3]['code'] = $this->rows[2]['code'];
        }, 'Duplicate or invalid canonical');
    }

    public function test_missing_parent_is_not_reconstructed_from_identifier_prefix(): void
    {
        $this->rejected(function (): void {
            $this->rows[2]['province_code'] = '9909900000';
        }, 'Missing or wrong-level parent');
    }

    public function test_cross_region_and_wrong_level_ancestry_are_rejected(): void
    {
        $this->rows[] = $this->place('9800000000', 'region', null, null, null, 'Second Synthetic Region');
        $this->manifest['expected_counts']['region']++;
        $this->rejected(function (): void {
            $this->rows[2]['region_code'] = '9800000000';
        }, 'Parent belongs to a different region');
    }

    public function test_barangay_parent_context_must_agree_with_its_city(): void
    {
        $this->rejected(function (): void {
            $this->rows[5]['province_code'] = null;
        }, 'Barangay province disagrees');
    }

    public function test_count_mismatch_and_unknown_levels_cannot_be_silently_skipped(): void
    {
        $this->rejected(function (): void {
            $this->manifest['expected_counts']['barangay'] = 0;
        }, 'Level count mismatch');
    }

    public function test_changed_artifact_bytes_are_rejected_before_any_import(): void
    {
        file_put_contents($this->package.'/source.txt', 'Changed source');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        app(GeographicSnapshot::class)->inspect($this->package.'/manifest.json');
    }

    public function test_local_path_traversal_and_missing_row_evidence_are_rejected(): void
    {
        $this->manifest['records']['path'] = '../places.json';
        file_put_contents($this->package.'/manifest.json', json_encode($this->manifest));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plain local filenames');
        app(GeographicSnapshot::class)->inspect($this->package.'/manifest.json');
    }

    public function test_html_block_page_cannot_be_imported_as_an_official_workbook(): void
    {
        $this->rejected(function (): void {
            $this->manifest['source_kind'] = 'official_candidate';
        }, 'XLSX archive');
    }

    public function test_same_edition_with_changed_manifest_is_rejected_without_partial_rows(): void
    {
        $this->stage();
        $this->manifest['attribution'] = 'Changed attribution';
        try {
            $this->stage();
            $this->fail('Expected immutable edition conflict.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('different manifest bytes', $error->getMessage());
        }
        $this->assertDatabaseCount('geographic_datasets', 1);
        $this->assertDatabaseCount('geographic_places', 6);
    }

    public function test_failed_insert_rolls_back_the_entire_staging_edition(): void
    {
        DB::unprepared("CREATE TRIGGER fail_geographic_insert BEFORE INSERT ON geographic_places BEGIN SELECT RAISE(ABORT, 'simulated staging failure'); END;");
        try {
            $this->stage();
            $this->fail('Expected isolated insertion failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('simulated staging failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER fail_geographic_insert');
        }
        $this->assertDatabaseCount('geographic_datasets', 0);
        $this->assertDatabaseCount('geographic_places', 0);
    }

    public function test_referenced_parent_cannot_be_changed_under_existing_children(): void
    {
        $this->stage();
        $this->manifest['edition'] = 'other-dataset';
        $id = $this->stage()['dataset_id'];
        $this->expectException(QueryException::class);
        DB::table('geographic_places')->where('dataset_id', $id)->where('level', 'region')->update(['code' => '9700000000']);
    }

    public function test_wrong_parent_levels_cannot_form_cycles(): void
    {
        $this->rejected(function (): void {
            $this->rows[0]['region_code'] = '9900101000';
        }, 'Only regions may omit');
    }

    public function test_missing_source_reference_is_rejected(): void
    {
        $this->rejected(function (): void {
            $this->rows[2]['source_ref'] = 'unknown.xlsx#row:3';
        }, 'Missing source row evidence');
    }

    public function test_staging_command_requires_explicit_guarded_database_and_never_uses_default(): void
    {
        $this->artisan('geography:stage', ['manifest' => $this->package.'/manifest.json'])->assertFailed();
        $this->artisan('geography:stage', ['manifest' => $this->package.'/manifest.json', '--database' => ':memory:'])->assertFailed();
        $this->assertDatabaseCount('geographic_datasets', 0);
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_isolated_command_initializes_only_directory_tables_and_refuses_overwrite(): void
    {
        $path = storage_path('app/private/geographic-staging-test-'.bin2hex(random_bytes(6)).'.sqlite');
        try {
            $this->artisan('geography:stage', ['manifest' => $this->package.'/manifest.json', '--database' => $path, '--initialize' => true])->assertSuccessful();
            $this->artisan('geography:stage', ['manifest' => $this->package.'/manifest.json', '--database' => $path])->assertSuccessful();
            $this->artisan('geography:stage', ['manifest' => $this->package.'/manifest.json', '--database' => $path, '--initialize' => true])->assertFailed();
            $pdo = new \PDO('sqlite:'.$path);
            $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM geographic_places')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'scholarships'")->fetchColumn());
            $pdo = null;
            $this->assertDatabaseCount('geographic_datasets', 0);
        } finally {
            DB::purge('geographic_staging');
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
