<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipLocation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AdminReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    }

    public function test_reports_and_exports_are_admin_only_and_have_no_write_routes(): void
    {
        foreach (['/admin/reports', '/admin/reports/export'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach ([StudentProfile::factory()->create()->user, ProviderProfile::factory()->create()->user] as $user) {
            foreach (['/admin/reports', '/admin/reports/export'] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
        $this->actingAs($this->admin());
        $this->post('/admin/reports')->assertStatus(405);
        $this->delete('/admin/reports')->assertStatus(405);
    }

    public function test_every_report_and_export_handles_an_empty_database(): void
    {
        $this->actingAs($this->admin());
        foreach (array_keys(AdminReports::DEFINITIONS) as $type) {
            $query = $this->filters($type);
            $this->get('/admin/reports?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('admin/Reports')->where('rows.total', 0)->where('filters.type', $type)->has('options', 8));
            $this->get('/admin/reports/export?'.$query)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->assertDownload('iskoreach-'.$type.'-2026-10-19-to-2026-10-19.csv');
        }
    }

    public function test_registration_report_groups_in_manila_and_excludes_admins_and_outside_dates(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['role' => UserRole::Student, 'created_at' => '2026-10-18 15:59:59']);
        User::factory()->count(2)->create(['role' => UserRole::Student, 'created_at' => '2026-10-18 16:00:00']);
        User::factory()->create(['role' => UserRole::Provider, 'created_at' => '2026-10-19 15:59:59']);
        User::factory()->create(['role' => UserRole::Student, 'created_at' => '2026-10-19 16:00:00']);
        $this->get('/admin/reports?'.$this->filters('registrations'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 2)->where('rows.data.0.date', '2026-10-19')->where('rows.data.0.role', 'provider')->where('rows.data.0.count', 1)
            ->where('rows.data.1.role', 'student')->where('rows.data.1.count', 2)->missing('rows.data.0.email'));
    }

    public function test_provider_report_includes_missing_profiles_without_including_admins(): void
    {
        $this->actingAs($this->admin());
        ProviderProfile::factory()->create(['verification_status' => 'approved']);
        ProviderProfile::factory()->count(2)->create(['verification_status' => 'pending']);
        User::factory()->create(['role' => UserRole::Provider]);
        $this->get('/admin/reports?'.$this->filters('providers'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 3)->where('rows.data.0.status', 'approved')->where('rows.data.0.count', 1)
            ->where('rows.data.1.status', 'pending')->where('rows.data.1.count', 2)
            ->where('rows.data.2.status', 'profile_missing')->where('rows.data.2.count', 1));
    }

    public function test_scholarship_status_and_location_reports_preserve_history_and_deduplicate_locations(): void
    {
        $this->actingAs($this->admin());
        $target = Scholarship::factory()->create(['status' => 'published']);
        $retained = Scholarship::factory()->create(['status' => 'published']);
        $retained->delete();
        ScholarshipLocation::factory()->count(2)->create(['scholarship_id' => $target->id, 'scope' => 'region', 'region_name' => 'NCR']);
        $this->get('/admin/reports?'.$this->filters('scholarships'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 2)->where('rows.data.0.visibility', 'current')->where('rows.data.0.count', 1)
            ->where('rows.data.1.visibility', 'retained')->where('rows.data.1.count', 1));
        $this->get('/admin/reports?'.$this->filters('locations'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 2)->where('rows.data.0.scope', 'not_recorded')->where('rows.data.0.count', 1)
            ->where('rows.data.1.scope', 'region')->where('rows.data.1.region', 'NCR')->where('rows.data.1.count', 1));
    }

    public function test_application_report_counts_waitlisted_retained_submissions_but_not_drafts_or_wrong_roles(): void
    {
        $this->actingAs($this->admin());
        $scholarship = Scholarship::factory()->create(['title' => 'Merit grant']);
        $waitlisted = Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => 'waitlisted']);
        $waitlisted->delete();
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => 'requirements_pending', 'submitted_at' => null]);
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => 'submitted', 'submitted_at' => '2026-10-17 12:00:00']);
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'student_id' => $this->admin()->id]);
        $this->get('/admin/reports?'.$this->filters('applications'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.status', 'waitlisted')->where('rows.data.0.count', 1)
            ->where('rows.data.0.scholarship', 'Merit grant')->missing('rows.data.0.student_id'));
    }

    public function test_active_awards_and_renewals_use_recorded_statuses(): void
    {
        $this->actingAs($this->admin());
        ScholarshipAward::factory()->create();
        ScholarshipAward::factory()->create(['starts_on' => '2026-10-20']);
        ScholarshipAward::factory()->create(['status' => 'revoked']);
        Renewal::factory()->create(['status' => 'submitted']);
        Renewal::factory()->create(['status' => 'approved']);
        $this->get('/admin/reports?'.$this->filters('active_awards'))->assertInertia(fn (Assert $page) => $page->where('rows.total', 3));
        $this->get('/admin/reports?'.$this->filters('renewals'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 2)->where('rows.data.0.status', 'approved')->where('rows.data.1.status', 'submitted'));
    }

    public function test_release_report_keeps_currencies_separate_and_flags_incomplete_records(): void
    {
        $this->actingAs($this->admin());
        BenefitRelease::factory()->create(['amount' => '0.10']);
        BenefitRelease::factory()->create(['amount' => '0.20']);
        BenefitRelease::factory()->create(['amount' => '7.25', 'currency' => 'USD']);
        BenefitRelease::factory()->create(['status' => 'released', 'released_at' => null]);
        $this->get('/admin/reports?'.$this->filters('releases'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 3)->where('rows.data.0.currency', 'PHP')->where('rows.data.0.amount', '0.30')->where('rows.data.0.count', 2)
            ->where('rows.data.1.currency', 'USD')->where('rows.data.1.amount', '7.25')
            ->where('rows.data.2.status', 'released')->where('rows.data.2.incomplete', 1));
    }

    public function test_retired_external_aggregate_report_cannot_be_requested(): void
    {
        $this->actingAs($this->admin())->get('/admin/reports?'.$this->filters('external_counts'))->assertSessionHasErrors('type');
    }

    public function test_export_includes_all_groups_and_escapes_spreadsheet_formulas_without_private_student_data(): void
    {
        $this->actingAs($this->admin());
        $student = StudentProfile::factory()->create()->user;
        Application::factory()->create(['student_id' => $student->id, 'scholarship_id' => Scholarship::factory()->create(['title' => "\t=HYPERLINK(test)"])->id]);
        for ($index = 0; $index < 16; $index++) {
            Application::factory()->create();
        }
        $this->get('/admin/reports?'.$this->filters('applications'))->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 15)->where('rows.total', 17)->where('rows.next_page_url', fn ($url) => str_contains($url, 'type=applications')));
        $response = $this->get('/admin/reports/export?'.$this->filters('applications'))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'\t=HYPERLINK(test)", $csv);
        $this->assertStringNotContainsString($student->email, $csv);
        $this->assertStringNotContainsString($student->name, $csv);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        $this->assertCount(20, $rows);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_invalid_filters_and_excessive_windows_are_rejected_for_page_and_export(): void
    {
        $this->actingAs($this->admin());
        foreach (['/admin/reports', '/admin/reports/export'] as $path) {
            foreach (['type' => 'users_passwords', 'from' => '2026-02-30', 'to' => 'invalid'] as $field => $value) {
                $this->get($path.'?'.http_build_query(['type' => 'registrations', 'from' => '2026-10-19', 'to' => '2026-10-19', $field => $value]))->assertSessionHasErrors($field);
            }
            $this->get($path.'?from=2026-10-20&to=2026-10-19')->assertSessionHasErrors('to');
            $this->get($path.'?from=2020-01-01&to=2026-10-19')->assertSessionHasErrors('to');
        }
    }

    private function filters(string $type): string
    {
        return http_build_query(['type' => $type, 'from' => '2026-10-19', 'to' => '2026-10-19']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }
}
