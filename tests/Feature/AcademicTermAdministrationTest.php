<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AcademicTermManagement;
use App\Services\ApplicationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicTermAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function data(?AcademicTerm $term = null, array $overrides = []): array
    {
        return array_replace(['academic_year' => $term?->academic_year ?? '2026-2027', 'semester' => $term?->semester ?? 'First semester',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31', 'confirmed' => true,
            'version' => $term ? app(AcademicTermManagement::class)->version($term) : null], $overrides);
    }

    public function test_empty_database_exposes_an_empty_term_list_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/admin/academic-terms')->assertInertia(fn (Assert $page) => $page->where('terms.total', 0)->has('terms.data', 0));
    }

    public function test_admin_empty_list_and_guest_and_other_role_restrictions(): void
    {
        $term = AcademicTerm::factory()->create();
        $this->get('/admin/academic-terms')->assertRedirect('/login');
        $this->post('/admin/academic-terms', $this->data())->assertRedirect('/login');
        foreach ([StudentProfile::factory()->create()->user, ProviderProfile::factory()->create()->user] as $user) {
            $this->actingAs($user)->get('/admin/academic-terms')->assertForbidden();
            $this->post('/admin/academic-terms', $this->data())->assertForbidden();
            $this->patch('/admin/academic-terms/'.$term->id, $this->data($term))->assertForbidden();
        }
        $this->actingAs($this->admin())->get('/admin/academic-terms?q=no-match')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/AcademicTerms')->where('terms.total', 0)->where('adminNavigation.terms', route('admin.academic-terms.index')));
        $this->delete('/admin/academic-terms/'.$term->id)->assertStatus(405);
    }

    public function test_creates_and_edits_unused_terms_with_audit_and_ignores_injected_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/academic-terms', $this->data(null, ['academic_year' => ' 2026-2027 ', 'semester' => ' First semester ', 'is_active' => true, 'status' => 'active', 'id' => 98765]))->assertSessionHasNoErrors()->assertRedirect(route('admin.academic-terms.index'));
        $term = AcademicTerm::sole();
        $this->assertSame('2026-2027', $term->academic_year);
        $this->assertSame('First semester', $term->semester);
        $this->assertNotSame(98765, $term->id);
        $this->assertSame('2026-08-01', $term->starts_on->toDateString());
        $this->patch('/admin/academic-terms/'.$term->id, $this->data($term, ['semester' => 'Second semester', 'starts_on' => null, 'ends_on' => null]))->assertSessionHasNoErrors();
        $term->refresh();
        $this->assertSame('Second semester', $term->semester);
        $this->assertNull($term->starts_on);
        $this->assertNull($term->ends_on);
        $logs = ActivityLog::where('subject_type', AcademicTerm::class)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs[0]->actor_id);
        $this->assertSame('First semester', $logs[1]->changes['before']['semester']);
        $this->assertSame('Second semester', $logs[1]->changes['after']['semester']);
        $this->get('/admin/activity-logs?type=academic_term')->assertInertia(fn (Assert $page) => $page->where('logs.total', 2)->where('logs.data.0.action_code', 'academic_term.updated')->missing('logs.data.0.changes'));
    }

    public function test_validates_labels_dates_confirmation_and_duplicate_pairs_without_overlap_rules(): void
    {
        $this->actingAs($this->admin());
        foreach (['academic_year' => '', 'semester' => '', 'starts_on' => '2026-02-30', 'ends_on' => '2026-07-31', 'confirmed' => false] as $key => $value) {
            $this->post('/admin/academic-terms', $this->data(null, [$key => $value]))->assertSessionHasErrors($key);
        }
        $this->post('/admin/academic-terms', $this->data(null, ['academic_year' => str_repeat('a', 21)]))->assertSessionHasErrors('academic_year');
        $this->post('/admin/academic-terms', $this->data(null, ['semester' => str_repeat('a', 41)]))->assertSessionHasErrors('semester');
        $this->post('/admin/academic-terms', $this->data(null, ['ends_on' => '2026-08-01']))->assertSessionHasErrors('ends_on');
        $this->post('/admin/academic-terms', $this->data())->assertSessionHasNoErrors();
        $this->post('/admin/academic-terms', $this->data())->assertSessionHasErrors('academic_year');
        $this->post('/admin/academic-terms', $this->data(null, ['semester' => 'Summer term']))->assertSessionHasNoErrors();
        $this->post('/admin/academic-terms', $this->data(null, ['academic_year' => '2027-2028', 'starts_on' => null, 'ends_on' => null]))->assertSessionHasNoErrors();
        $this->assertSame(3, AcademicTerm::count());
    }

    public function test_duplicate_updates_stale_updates_and_unchanged_requests_are_safe(): void
    {
        $term = AcademicTerm::factory()->create(['academic_year' => '2026-2027', 'semester' => 'First semester']);
        $other = AcademicTerm::factory()->create(['academic_year' => '2026-2027', 'semester' => 'Second semester']);
        $this->actingAs($this->admin());
        $this->patch('/admin/academic-terms/'.$term->id, $this->data($term, ['semester' => $other->semester]))->assertSessionHasErrors('academic_year');
        $old = $this->data($term);
        $this->patch('/admin/academic-terms/'.$term->id, $old)->assertSessionHasNoErrors();
        $this->patch('/admin/academic-terms/'.$term->id, $old)->assertSessionHasErrors('version');
        $term->refresh();
        $this->patch('/admin/academic-terms/'.$term->id, $this->data($term))->assertSessionHasNoErrors()->assertSessionHas('status', 'No changes were made.');
        $this->assertSame(1, ActivityLog::where('subject_type', AcademicTerm::class)->count());
    }

    public function test_every_reference_type_and_retained_references_protect_term_details(): void
    {
        $this->actingAs($this->admin());
        foreach (AcademicTermManagement::REFERENCE_TABLES as $table) {
            $term = AcademicTerm::factory()->create();
            $record = match ($table) {
                'scholarships' => Scholarship::factory()->create(['academic_term_id' => $term->id]),
                'applications' => Application::factory()->create(['academic_term_id' => $term->id]),
                'scholarship_grants' => ScholarshipAward::factory()->create(['academic_term_id' => $term->id]),
                'renewals' => Renewal::factory()->create(['academic_term_id' => $term->id]),
                'payments' => BenefitRelease::factory()->create(['academic_term_id' => $term->id]),
            };
            if (in_array($table, ['scholarships', 'applications'], true)) {
                $record->delete();
            }
            $this->patch('/admin/academic-terms/'.$term->id, $this->data($term, ['semester' => 'Changed label']))->assertSessionHasErrors('term');
            $this->assertSame($term->semester, $term->fresh()->semester);
            $this->get('/admin/academic-terms?q='.urlencode($term->academic_year))->assertInertia(fn (Assert $page) => $page->where('terms.data.0.editable', false));
        }
        $this->assertSame(0, ActivityLog::where('subject_type', AcademicTerm::class)->count());
    }

    public function test_reference_added_after_the_form_opened_prevents_the_edit(): void
    {
        $term = AcademicTerm::factory()->create();
        $payload = $this->data($term, ['semester' => 'New name']);
        $program = Scholarship::factory()->create(['academic_term_id' => $term->id]);
        $this->actingAs($this->admin())->patch('/admin/academic-terms/'.$term->id, $payload)->assertSessionHasErrors('term');
        $this->assertSame($term->id, $program->fresh()->academic_term_id);
    }

    public function test_search_usage_sort_pagination_and_invalid_filters(): void
    {
        AcademicTerm::factory()->count(14)->create();
        $term = AcademicTerm::factory()->create(['academic_year' => '2099-2100', 'semester' => 'Final term']);
        Scholarship::factory()->create(['academic_term_id' => $term->id])->delete();
        $this->actingAs($this->admin())->get('/admin/academic-terms')->assertInertia(fn (Assert $page) => $page->where('terms.total', 15)->has('terms.data', 12));
        $this->get('/admin/academic-terms?usage=used')->assertInertia(fn (Assert $page) => $page->where('terms.total', 1)->where('terms.data.0.usage.scholarships', 1));
        $this->get('/admin/academic-terms?usage=unused&sort=year_asc')->assertInertia(fn (Assert $page) => $page->where('terms.total', 14)->where('terms.next_page_url', fn ($url) => str_contains($url, 'usage=unused') && str_contains($url, 'sort=year_asc')));
        $this->get('/admin/academic-terms?q=Final')->assertInertia(fn (Assert $page) => $page->where('terms.total', 1)->where('terms.data.0.id', $term->id));
        $this->get('/admin/academic-terms?usage=active')->assertSessionHasErrors('usage');
        $this->get('/admin/academic-terms?sort=unknown')->assertSessionHasErrors('sort');
    }

    public function test_existing_term_selectors_and_saved_application_term_remain_connected(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $term = AcademicTerm::factory()->create(['academic_year' => '2026-2027']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'academic_term_id' => $term->id, 'status' => 'published', 'published_at' => now()]);
        $student = StudentProfile::factory()->create()->user;
        $application = app(ApplicationWorkflow::class)->start($student, $program);
        $this->assertSame($term->id, $application->academic_term_id);
        $this->actingAs($provider->user)->get('/provider/scholarships/create')->assertInertia(fn (Assert $page) => $page->where('terms.0.id', $term->id));
        $this->get('/benefits/applications/'.$application->id.'/create')->assertForbidden();
        $application->forceFill(['status' => 'accepted', 'submitted_at' => now()])->save();
        $this->get('/benefits/applications/'.$application->id.'/create')->assertInertia(fn (Assert $page) => $page->where('terms.0.id', $term->id));
        $this->get('/scholarships?term='.$term->id)->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('terms.0.id', $term->id));
        $this->actingAs($student)->get('/student/applications/'.$application->id)->assertInertia(fn (Assert $page) => $page->where('application.academic_term', '2026-2027 / First semester'));
        $this->assertSame($term->id, $application->fresh()->academic_term_id);
    }

    public function test_term_save_and_audit_are_rolled_back_together(): void
    {
        $admin = $this->admin();
        ActivityLog::creating(function (ActivityLog $log): void {
            if ($log->subject_type === AcademicTerm::class) {
                throw new \RuntimeException('Term audit failure');
            }
        });
        try {
            app(AcademicTermManagement::class)->save($admin, $this->data());
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Term audit failure', $error->getMessage());
        } finally {
            ActivityLog::flushEventListeners();
        }
        $this->assertSame(0, AcademicTerm::count());
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_renewal_term_eligibility_uses_philippine_calendar_midnight(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 23:59:00', 'Asia/Manila'));
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id, 'starts_on' => '2026-10-01']);
        $term = AcademicTerm::factory()->create(['starts_on' => '2026-08-01', 'ends_on' => '2026-10-20']);
        $data = ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => '2026-10-23T12:00', 'confirmed' => true];
        $this->actingAs($provider->user)->get('/renewals')->assertInertia(fn (Assert $page) => $page->where('terms.0.id', $term->id));
        $this->post('/renewals', $data)->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 00:01:00', 'Asia/Manila'));
        $this->get('/renewals')->assertInertia(fn (Assert $page) => $page->has('terms', 0));
        $this->post('/renewals', $data)->assertSessionHasErrors('renewal');
        $this->travelBack();
        $this->assertSame(1, Renewal::count());
    }
}
