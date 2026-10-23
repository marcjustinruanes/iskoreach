<?php

namespace Tests\Feature;

use App\Enums\ScholarshipStatus;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\Scholarship;
use App\Models\ScholarshipLocation;
use App\Models\ScholarshipRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminScholarshipMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_is_admin_only_and_has_no_moderation_endpoints(): void
    {
        $scholarship = Scholarship::factory()->create();
        $this->get('/admin/scholarships')->assertRedirect('/login');
        $this->get('/admin/scholarships/'.$scholarship->id)->assertRedirect('/login');
        foreach ([StudentProfile::factory()->create()->user, $scholarship->provider->user] as $user) {
            $this->actingAs($user)->get('/admin/scholarships')->assertForbidden();
            $this->get('/admin/scholarships/'.$scholarship->id)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/scholarships/'.$scholarship->id, ['status' => 'published'])->assertStatus(405);
        $this->delete('/admin/scholarships/'.$scholarship->id)->assertStatus(405);
        $this->assertSame(ScholarshipStatus::Draft, $scholarship->fresh()->status);
    }

    public function test_empty_monitoring_uses_real_empty_results(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/scholarships')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Scholarships')->where('scholarships.total', 0)->has('providers', 0)->has('categories', 0));
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('summary.scholarships', 0)->where('summary.published_scholarships', 0)->has('recentScholarships', 0));
    }

    public function test_search_and_combined_filters_return_only_matching_scholarships(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = Scholarship::factory()->create(['title' => 'Campus Merit Grant', 'status' => ScholarshipStatus::Published]);
        $target->provider->update(['organization_name' => 'Campus Foundation']);
        ScholarshipLocation::factory()->create(['scholarship_id' => $target->id, 'scope' => 'region', 'region_name' => 'NCR']);
        ScholarshipLocation::factory()->create(['scholarship_id' => $target->id, 'scope' => 'city', 'region_name' => 'NCR', 'city_name' => 'CITY-TEST']);
        Scholarship::factory()->create(['scholarship_type' => 'Need based']);
        $filters = ['q' => 'Campus', 'status' => 'published', 'category' => 'Merit', 'provider' => $target->provider_id, 'location' => 'NCR'];
        $this->actingAs($admin)->get('/admin/scholarships?'.http_build_query($filters))->assertInertia(fn (Assert $page) => $page
            ->where('scholarships.total', 1)->where('scholarships.data.0.id', $target->id)->has('scholarships.data.0.locations', 2)
            ->where('filters.location', 'NCR')->where('scholarships.data.0.applicant_count', 0));
        $this->get('/admin/scholarships?q=Campus%20Foundation')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1));
        $this->get('/admin/scholarships?location=CITY-TEST')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1));
        $this->get('/admin/scholarships?status=archived')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 0));
    }

    public function test_opening_and_deadline_filters_use_inclusive_manila_calendar_days(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['2026-10-18 15:59:59', '2026-10-18 16:00:00', '2026-10-19 15:59:59', '2026-10-19 16:00:00'] as $time) {
            Scholarship::factory()->create(['opens_at' => $time, 'deadline_at' => $time]);
        }
        foreach (['opens', 'deadline'] as $field) {
            $this->actingAs($admin)->get('/admin/scholarships?'.$field.'_from=2026-10-19&'.$field.'_to=2026-10-19')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
            $this->get('/admin/scholarships?'.$field.'_to=2026-10-19')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 3));
        }
    }

    public function test_invalid_filters_are_rejected_on_the_server(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['status' => 'awaiting_approval', 'records' => 'destroyed', 'provider' => '999999', 'q' => str_repeat('x', 201), 'opens_from' => 'bad-date', 'deadline_to' => '2026-02-30'] as $field => $value) {
            $this->actingAs($admin)->get('/admin/scholarships?'.http_build_query([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->get('/admin/scholarships?opens_from=2026-10-20&opens_to=2026-10-19')->assertSessionHasErrors('opens_to');
        $this->get('/admin/scholarships?deadline_from=2026-10-20&deadline_to=2026-10-19')->assertSessionHasErrors('deadline_to');
    }

    public function test_details_show_recorded_criteria_and_ordered_requirements_without_private_provider_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $term = AcademicTerm::factory()->create(['academic_year' => '2026-2027']);
        $scholarship = Scholarship::factory()->create(['academic_term_id' => $term->id, 'eligibility_rules' => ['minimum_grade' => 85], 'accepts_existing_scholars' => true]);
        $scholarship->provider->update(['contact_email' => 'private-provider-contact@example.test']);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $scholarship->id, 'name' => 'Second requirement', 'sort_order' => 2]);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $scholarship->id, 'name' => 'First requirement', 'sort_order' => 1]);
        $this->actingAs($admin)->get('/admin/scholarships/'.$scholarship->id)->assertInertia(fn (Assert $page) => $page
            ->component('admin/ScholarshipDetails')->where('scholarship.eligibility_rules.minimum_grade', 85)
            ->where('scholarship.accepts_existing_scholars', true)->where('scholarship.requirements.0.name', 'First requirement')
            ->where('scholarship.applicant_count', 0)->where('scholarship.benefit_amount', '15000.00')
            ->where('scholarship.academic_term', '2026-2027')->missing('scholarship.contact_email'))
            ->assertDontSee('private-provider-contact@example.test');
    }

    public function test_retained_records_remain_readable_with_requirements_and_locations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $scholarship = Scholarship::factory()->create(['status' => ScholarshipStatus::Closed]);
        ScholarshipLocation::factory()->create(['scholarship_id' => $scholarship->id]);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $scholarship->id]);
        $scholarship->delete();
        $this->actingAs($admin)->get('/admin/scholarships?records=retained')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1));
        $this->get('/admin/scholarships?records=current')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 0));
        $this->get('/admin/scholarships/'.$scholarship->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('scholarship.locations', 1)->has('scholarship.requirements', 1)->where('scholarship.status', 'closed'));
        $this->assertSoftDeleted($scholarship);
    }

    public function test_pagination_preserves_the_selected_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Scholarship::factory()->count(12)->create(['status' => ScholarshipStatus::Published]);
        $this->actingAs($admin)->get('/admin/scholarships?status=published&category=Merit')->assertInertia(fn (Assert $page) => $page
            ->has('scholarships.data', 10)->where('scholarships.total', 12)
            ->where('scholarships.next_page_url', fn ($url) => str_contains($url, 'status=published') && str_contains($url, 'category=Merit')));
        $this->get('/admin/scholarships?status=published&category=Merit&page=2')->assertInertia(fn (Assert $page) => $page->has('scholarships.data', 2));
    }

    public function test_dashboard_counts_and_recent_records_come_from_scholarships(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Scholarship::factory()->create(['status' => ScholarshipStatus::Published]);
        $removed = Scholarship::factory()->create(['status' => ScholarshipStatus::Published]);
        $removed->delete();
        Scholarship::factory()->create();
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('summary.scholarships', 3)->where('summary.published_scholarships', 1)->has('recentScholarships', 3));
    }

    public function test_rerunning_the_foundation_migration_preserves_existing_records_and_uses_short_index_names(): void
    {
        $scholarship = Scholarship::factory()->create();
        $migration = require database_path('migrations/2026_10_19_123304_create_scholarship_monitoring_tables.php');
        $migration->up();
        $this->assertDatabaseHas('scholarships', ['id' => $scholarship->id, 'title' => $scholarship->title]);
        $this->assertTrue(Schema::hasIndex('scholarship_requirements', 'scholarship_requirement_stage_order'));
        foreach (Schema::getIndexes('scholarship_requirements') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']));
        }
    }
}
