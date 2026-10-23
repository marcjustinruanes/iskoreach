<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\GeographicLegacyMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GeographicLegacyMappingTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $manifest;

    private array $places;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iskoreach-mapping-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/evidence.txt', 'Synthetic mapping fixtures, not PSA data.');
        $this->places = [
            $this->place('9900000000', 'region', 'Old Region', null, '990000000'),
            $this->place('9800000000', 'region', 'New Region', null, '980000000'),
            $this->place('9900101000', 'city', 'Repeated Place', '9900000000', '990010100'),
            $this->place('9800101000', 'city', 'Repeated Place', '9800000000', '980010100'),
        ];
        $this->manifest = $this->save();
    }

    protected function tearDown(): void
    {
        foreach (['evidence.txt', 'places.json', 'manifest.json', 'coverage.json'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function place(string $code, string $level, string $name, ?string $region, string $legacy): array
    {
        return ['code' => $code, 'level' => $level, 'name' => $name, 'region_code' => $region, 'province_code' => null, 'city_code' => null,
            'source_ref' => 'evidence.txt#row:'.$code, 'correspondences' => [['code' => $legacy, 'source_ref' => 'evidence.txt#correspondence:'.$legacy]]];
    }

    private function save(): string
    {
        file_put_contents($this->directory.'/places.json', json_encode($this->places));
        $manifest = ['schema_version' => 1, 'edition' => 'synthetic-mapping', 'source_kind' => 'synthetic', 'publisher' => 'Tests',
            'source_url' => 'https://example.test/source', 'terms_url' => 'https://example.test/terms', 'attribution' => 'Synthetic fixtures',
            'transformation' => 'Explicit fixture relationships', 'parser_version' => 'fixture1', 'as_of' => '2026-09-30', 'released_on' => '2026-10-12', 'retrieved_at' => '2026-10-20T12:00:00Z',
            'files' => [['path' => 'evidence.txt', 'sha256' => hash_file('sha256', $this->directory.'/evidence.txt')]],
            'records' => ['path' => 'places.json', 'sha256' => hash_file('sha256', $this->directory.'/places.json')],
            'expected_counts' => ['region' => 2, 'province' => 0, 'city' => 2, 'municipality' => 0, 'barangay' => 0]];
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest));

        return $this->directory.'/manifest.json';
    }

    private function coverage(array $changes = []): array
    {
        return ['id' => 1, 'scholarship_id' => 1, 'scope' => 'city', 'region_code' => '990000000', 'city_code' => '990010100',
            'region_name' => 'Old Region', 'city_name' => 'Repeated Place', 'created_at' => '2026-10-20 00:00:00', 'updated_at' => '2026-10-20 00:00:00', ...$changes];
    }

    private function report(array $rows): array
    {
        return app(GeographicLegacyMapping::class)->report($this->manifest, $rows);
    }

    public function test_exact_proposals_retain_values_and_evidence_but_are_never_approved_or_applied(): void
    {
        $old = $this->coverage(['city_name' => '  REPEATED   Place  ']);
        $report = $this->report([$old]);
        $row = $report['rows'][0];
        $this->assertSame('exact', $row['classification']);
        $this->assertSame($old, $row['original']);
        $this->assertSame('9900101000', $row['proposed']['code']);
        $this->assertCount(2, $row['evidence']);
        $this->assertSame('unreviewed', $row['review_status']);
        $this->assertTrue($row['requires_review']);
        $this->assertFalse($row['apply_available']);
        $this->assertFalse($report['authority_verified']);
        $this->assertFalse(Schema::hasTable('geographic_datasets'));
        $this->assertDatabaseCount('scholarship_locations', 0);
        Http::assertNothingSent();
    }

    public function test_renamed_moved_and_combined_changes_are_separate_review_proposals(): void
    {
        $renamed = $this->report([$this->coverage(['city_name' => 'Previous label'])])['rows'][0];
        $this->assertSame('renamed_or_changed', $renamed['classification']);
        $this->places[2]['region_code'] = '9800000000';
        $this->save();
        $moved = $this->report([$this->coverage()])['rows'][0];
        $this->assertSame('moved', $moved['classification']);
        $combined = $this->report([$this->coverage(['city_name' => 'Previous label'])])['rows'][0];
        $this->assertContains('parent_changed', $combined['flags']);
        $this->assertContains('saved_label_differs', $combined['flags']);
    }

    public function test_identifier_collisions_are_not_resolved_using_similar_names_or_region_hints(): void
    {
        $this->places[3]['correspondences'] = $this->places[2]['correspondences'];
        $this->save();
        $row = $this->report([$this->coverage()])['rows'][0];
        $this->assertSame('ambiguous', $row['classification']);
        $this->assertNull($row['proposed']);
        $this->assertCount(2, $row['candidates']);
    }

    public function test_unmatched_codes_and_unsupported_scopes_are_never_guessed_from_names(): void
    {
        $row = $this->report([$this->coverage(['city_code' => 'unmatched'])])['rows'][0];
        $this->assertSame('unmatched', $row['classification']);
        $this->assertNull($row['proposed']);
        $row = $this->report([$this->coverage(['scope' => 'province'])])['rows'][0];
        $this->assertContains('unsupported_legacy_scope', $row['flags']);
    }

    public function test_missing_labels_and_unresolved_hierarchy_require_manual_review(): void
    {
        $row = $this->report([$this->coverage(['city_name' => null])])['rows'][0];
        $this->assertSame('ambiguous', $row['classification']);
        $this->assertContains('saved_label_missing', $row['flags']);
        $row = $this->report([$this->coverage(['region_code' => 'unknown'])])['rows'][0];
        $this->assertContains('legacy_region_unresolved', $row['flags']);
        $this->assertNull($row['proposed']);
    }

    public function test_region_and_nationwide_coverage_have_explicit_non_guessing_results(): void
    {
        $row = $this->report([$this->coverage(['scope' => 'region', 'city_code' => null, 'city_name' => null])])['rows'][0];
        $this->assertSame('exact', $row['classification']);
        $row = $this->report([$this->coverage(['scope' => 'nationwide', 'region_code' => null, 'city_code' => null])])['rows'][0];
        $this->assertSame('nationwide', $row['classification']);
        $this->assertNull($row['proposed']);
        $row = $this->report([$this->coverage(['scope' => 'nationwide'])])['rows'][0];
        $this->assertSame('ambiguous', $row['classification']);
    }

    public function test_coverage_and_edition_changes_invalidate_proposal_fingerprints(): void
    {
        $old = $this->coverage();
        $report = $this->report([$old]);
        $mapping = app(GeographicLegacyMapping::class);
        $this->assertTrue($mapping->isCurrent($report['rows'][0], $old, $report['target_manifest_digest']));
        $this->assertFalse($mapping->isCurrent($report['rows'][0], $this->coverage(['updated_at' => '2026-10-21 00:00:00']), $report['target_manifest_digest']));
        $this->assertFalse($mapping->isCurrent($report['rows'][0], $old, str_repeat('0', 64)));
    }

    public function test_empty_inventory_is_honest_and_duplicate_ids_fail(): void
    {
        $report = $this->report([]);
        $this->assertSame([], $report['rows']);
        $this->assertSame(0, array_sum($report['counts']));
        $this->expectException(RuntimeException::class);
        $this->report([$this->coverage(), $this->coverage()]);
    }

    public function test_command_requires_explicit_input_and_exports_only_selected_context(): void
    {
        $this->artisan('geography:mapping-report', ['manifest' => $this->manifest])->assertFailed();
        file_put_contents($this->directory.'/coverage.json', json_encode([$this->coverage(['secret' => 'must not be exported'])]));
        $this->artisan('geography:mapping-report', ['manifest' => $this->manifest, '--coverage' => $this->directory.'/coverage.json'])->assertSuccessful();
        $row = $this->report([$this->coverage(['secret' => 'must not be exported'])])['rows'][0];
        $this->assertArrayNotHasKey('secret', $row['original']);
        $this->assertFalse(Schema::hasTable('geographic_datasets'));
    }

    public function test_current_database_reporting_keeps_real_coverage_and_history_unchanged(): void
    {
        $program = Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()]);
        $location = $program->locations()->create(['scope' => 'city', 'region_code' => '990000000', 'city_code' => '990010100', 'region_name' => 'Old Region', 'city_name' => 'Repeated Place']);
        $before = DB::table('scholarship_locations')->get()->toJson();
        $this->artisan('geography:mapping-report', ['manifest' => $this->manifest, '--current' => true])->assertSuccessful();
        $this->assertSame($before, DB::table('scholarship_locations')->get()->toJson());
        $this->assertDatabaseHas('scholarship_locations', ['id' => $location->id, 'city_name' => 'Repeated Place']);
        $this->assertFalse(Schema::hasTable('geographic_datasets'));
    }
}
