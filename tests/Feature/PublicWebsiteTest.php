<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_has_real_empty_states_without_sample_or_private_records(): void
    {
        Scholarship::factory()->create(['title' => 'Private unpublished title']);
        ScholarshipAward::factory()->create();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')->has('recentScholarships', 0)
            ->where('publicStats', ['programs' => 0, 'open' => 0, 'passers' => 0]))
            ->assertDontSee('Private unpublished title');
    }

    public function test_home_uses_latest_public_programs_and_counts_only_eligible_public_records(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $visible = collect();
        for ($index = 0; $index < 4; $index++) {
            $visible->push(Scholarship::factory()->create(['provider_id' => $provider->id, 'title' => 'Public program '.$index,
                'status' => 'published', 'published_at' => now()->subDays(4 - $index),
                'opens_at' => now()->subDay(), 'deadline_at' => now()->addMonth()]));
        }
        $closed = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'closed',
            'published_at' => now()->subMonth(), 'deadline_at' => now()->subDay()]);
        $future = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published',
            'published_at' => now()->subMonth(), 'opens_at' => now()->addWeek(), 'deadline_at' => now()->addMonth()]);
        $retained = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $retained->delete();
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'archived', 'published_at' => now()]);
        Scholarship::factory()->create(['status' => 'published', 'published_at' => now()]);
        $award = ScholarshipAward::factory()->create();
        $award->application->scholarship->provider->forceFill(['verification_status' => 'approved'])->save();
        $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'public_name' => 'Consenting scholar'])->save();
        // Factory defaults are not treated as evidence of active eligibility.
        $award->application->forceFill(['status' => 'accepted', 'submitted_at' => now()])->save();
        $award->forceFill(['status' => 'active', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null, 'revoked_at' => null])->save();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('recentScholarships', 3)
            ->where('recentScholarships.0.id', $visible[3]->id)
            ->where('recentScholarships.2.id', $visible[1]->id)
            ->where('publicStats.programs', 6)->where('publicStats.open', 4)->where('publicStats.passers', 1)
            ->missing('recentScholarships.0.contact_email')->missing('recentScholarships.0.applications')
            ->missing('recentScholarships.0.provider_id'))
            ->assertDontSee($provider->contact_email)->assertDontSee($award->application->student->email);
        $award->forceFill(['public_listing_consented_at' => null])->save();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('publicStats.passers', 0));
        $provider->forceFill(['verification_status' => 'pending'])->save();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('recentScholarships', 0)->where('publicStats.open', 0));
    }

    public function test_guest_application_entry_preserves_the_program_through_login_without_creating_an_application(): void
    {
        $program = Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id,
            'status' => 'published', 'published_at' => now()]);
        $student = StudentProfile::factory()->create()->user;
        $entry = route('scholarships.apply', $program->slug);
        $this->get($entry)->assertRedirect('/login')->assertSessionHas('url.intended', $entry);
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect($entry);
        $this->get($entry)->assertRedirect(route('scholarships.show', $program->slug));
        $this->get(route('scholarships.show', $program->slug))->assertOk();
        $this->assertSame(0, Application::count());
    }

    public function test_application_entry_rechecks_visibility_and_student_role(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $program = Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id,
            'status' => 'published', 'published_at' => now()]);
        $this->actingAs($student)->get(route('scholarships.apply', $program->slug))->assertRedirect(route('scholarships.show', $program->slug));
        $program->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->get(route('scholarships.apply', $program->slug))->assertNotFound();
        $this->actingAs(ProviderProfile::factory()->create()->user)->get(route('scholarships.apply', $program->slug))->assertForbidden();
        $this->assertSame(0, Application::count());
    }
}
