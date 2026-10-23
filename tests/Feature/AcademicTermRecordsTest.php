<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicTermRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function publishedGrant(): ScholarshipAward
    {
        $award = ScholarshipAward::factory()->create();
        $award->application->scholarship->provider->forceFill(['verification_status' => 'approved'])->save();
        $award->application->scholarship->update(['status' => 'published', 'published_at' => now()]);
        $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'public_name' => 'Approved Scholar'])->save();

        return $award;
    }

    public function test_public_passers_filter_by_original_or_approved_renewal_term_without_exposing_pending_renewals(): void
    {
        $this->freezeTime();
        $award = $this->publishedGrant();
        $renewalTerm = AcademicTerm::factory()->create(['academic_year' => '2030-2031']);
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $renewalTerm->id, 'status' => 'approved', 'reviewed_at' => now()]);
        $pendingTerm = AcademicTerm::factory()->create();
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $pendingTerm->id]);
        $unreviewed = AcademicTerm::factory()->create();
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $unreviewed->id, 'status' => 'approved']);

        $this->get('/passers?term='.$renewalTerm->id.'&type=renewal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('passers.total', 1)->has('terms', 2)->where('passers.data.0.renewal_terms.0', '2030-2031')->missing('passers.data.0.email'));
        $this->get('/passers?term='.$renewalTerm->id.'&type=new')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->get('/passers?term='.$award->academic_term_id.'&type=new')->assertInertia(fn (Assert $page) => $page->where('passers.total', 1));
        $this->get('/passers?term='.$pendingTerm->id)->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->get('/passers?term='.$unreviewed->id)->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->get('/passers?type=invalid')->assertSessionHasErrors('type');
        $this->get('/passers?term=999999')->assertSessionHasErrors('term');
    }

    public function test_provider_terms_remain_available_after_filtering_and_other_providers_records_stay_private(): void
    {
        $this->freezeTime();
        $award = $this->publishedGrant();
        $other = $this->publishedGrant();
        $term = AcademicTerm::factory()->create();
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $term->id, 'status' => 'approved', 'reviewed_at' => now()]);

        $this->actingAs($award->application->scholarship->provider->user)->get('/benefits?term='.$term->id.'&type=renewal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('awards.total', 1)->where('awards.data.0.id', $award->id)->where('awards.data.0.published', true)->has('terms', 2));
        $this->get('/benefits?term='.$other->academic_term_id)->assertInertia(fn (Assert $page) => $page->where('awards.total', 0)->has('terms', 2));
        $this->get('/renewals?term='.$term->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('renewals.total', 1)->where('filterTerms.0.id', $term->id));
        $this->get('/renewals?term='.$other->academic_term_id)->assertInertia(fn (Assert $page) => $page->where('renewals.total', 0)->has('filterTerms', 1));
    }

    public function test_unapproved_provider_grants_are_hidden_from_students_and_public_passers_until_approved(): void
    {
        $this->freezeTime();
        $award = $this->publishedGrant();
        $provider = $award->application->scholarship->provider;
        $provider->forceFill(['verification_status' => 'pending'])->save();

        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0)->has('terms', 0));
        $this->actingAs($award->application->student)->get('/benefits')->assertInertia(fn (Assert $page) => $page->where('awards.total', 0));
        $this->get('/student/awards')->assertInertia(fn (Assert $page) => $page->where('awards.total', 0));
        $this->get('/benefits/'.$award->id)->assertForbidden();
        $provider->forceFill(['verification_status' => 'approved'])->save();
        $this->get('/benefits')->assertInertia(fn (Assert $page) => $page->where('awards.total', 1));
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 1));
        $this->assertModelExists($award);
    }

    public function test_admin_academic_terms_show_new_grants_and_renewals_for_their_own_terms(): void
    {
        $this->freezeTime();
        $award = $this->publishedGrant();
        $term = AcademicTerm::factory()->create();
        $renewal = Renewal::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $term->id, 'status' => 'approved', 'reviewed_at' => now()]);
        $hidden = $this->publishedGrant();
        $hidden->application->scholarship->provider->forceFill(['verification_status' => 'pending'])->save();

        $this->get('/admin/academic-terms?term='.$term->id)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/academic-terms?term='.$term->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('termRecords.grants.total', 0)->where('termRecords.renewals.total', 1)->where('termRecords.renewals.data.0.id', $renewal->id));
        $this->get('/admin/academic-terms?term='.$award->academic_term_id)->assertInertia(fn (Assert $page) => $page
            ->where('termRecords.grants.total', 1)->where('termRecords.grants.data.0.published', true)->where('termRecords.renewals.total', 0));
        $this->get('/admin/academic-terms?term='.$hidden->academic_term_id)->assertInertia(fn (Assert $page) => $page->where('termRecords.grants.total', 0));
        $this->get('/admin/awards')->assertInertia(fn (Assert $page) => $page->where('records.total', 1)->has('providers', 1)->where('providers.0.id', $award->application->scholarship->provider_id));
        $this->get('/admin/academic-terms?term=999999')->assertSessionHasErrors('term');
    }

    public function test_academic_year_requires_consecutive_years_without_a_fixed_year_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $data = ['academic_year' => '2040-2041', 'semester' => 'First semester', 'confirmed' => true];

        $this->post('/admin/academic-terms', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_terms', ['academic_year' => '2040-2041']);
        $this->post('/admin/academic-terms', array_replace($data, ['academic_year' => '2040-2042']))->assertSessionHasErrors(['academic_year' => 'Use consecutive academic years, for example 2025-2026.']);
        $this->post('/admin/academic-terms', array_replace($data, ['academic_year' => 'next year']))->assertSessionHasErrors('academic_year');
    }

    public function test_open_scholarship_counts_include_individual_external_and_waitlisted_applicants_but_not_drafts(): void
    {
        $this->freezeTime();
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'eligibility_rules' => ['provider_settings' => ['slots' => 20]]]);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'submitted', 'student_id' => null, 'applicant_source' => 'external', 'applicant_name' => 'External Applicant', 'applicant_email' => 'external@example.com']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'not_started', 'submitted_at' => null]);

        $this->actingAs($provider->user)->get('/provider/scholarships')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scholarships.data.0.open', true)->where('scholarships.data.0.slots', 20)->where('scholarships.data.0.submitted_count', 2)->where('scholarships.data.0.waitlisted_count', 1));
        $this->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page
            ->where('scholarship.applicant_count', 2)->where('scholarship.waitlisted_count', 1)->where('scholarship.slots', 20));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/scholarships')->assertInertia(fn (Assert $page) => $page
            ->where('scholarships.data.0.open', true)->where('scholarships.data.0.applicant_count', 2)->where('scholarships.data.0.waitlisted_count', 1)->where('scholarships.data.0.slots', 20));
        $program->update(['status' => 'closed']);
        $this->get('/admin/scholarships')->assertInertia(fn (Assert $page) => $page->where('scholarships.data.0.open', false));
    }
}
