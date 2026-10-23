<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentScholarshipBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_catalog_routes_require_a_student_account(): void
    {
        foreach (['/student/scholarships', '/student/scholarships/example'] as $path) {
            $this->get($path)->assertRedirect('/login');
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get($path)->assertForbidden();
            $this->actingAs(ProviderProfile::factory()->create()->user)->get($path)->assertForbidden();
            $this->post('/logout');
        }
    }

    public function test_catalog_filters_links_and_pagination_stay_in_the_student_workspace(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        Scholarship::factory()->count(13)->create(['provider_id' => $provider->id, 'title' => 'Student catalog grant', 'published_at' => now(), 'status' => 'published']);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'title' => 'Unpublished record']);
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->get('/student/scholarships?q=catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('public/Scholarships')->where('studentWorkspace', true)
            ->where('browseUrl', route('student.scholarships.index'))->where('filters.q', 'catalog')
            ->where('scholarships.total', 13)->has('scholarships.data', 6)
            ->where('scholarships.data.0.show_url', fn (string $url): bool => str_contains($url, '/student/scholarships/'))
            ->where('scholarships.next_page_url', fn (string $url): bool => str_contains($url, '/student/scholarships?') && str_contains($url, 'q=catalog') && str_contains($url, 'page=2')));
        $this->get('/student/scholarships?page=2&q=catalog')->assertInertia(fn (Assert $page) => $page->has('scholarships.data', 6)->where('scholarships.current_page', 2));
        $this->get('/student/scholarships?page=3&q=catalog')->assertInertia(fn (Assert $page) => $page->has('scholarships.data', 1)->where('scholarships.last_page', 3)->where('scholarships.links.3.active', true));
        $this->get('/student/scholarships?q=unpublished')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 0));
    }

    public function test_student_details_keep_workspace_navigation_and_hide_unpublished_programs(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $student = StudentProfile::factory()->create()->user;
        $url = route('student.scholarships.show', $program->slug);
        $this->actingAs($student)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('public/ScholarshipDetails')->where('studentWorkspace', true)
            ->where('browseUrl', route('student.scholarships.index'))->where('scholarship.show_url', $url));
        $program->update(['status' => 'draft']);
        $this->get($url)->assertNotFound();
        $program->update(['status' => 'published']);
        $provider->forceFill(['verification_status' => 'pending'])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_public_directory_keeps_public_layout_and_urls(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $this->get('/scholarships')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('studentWorkspace', false)->where('browseUrl', route('scholarships.index'))
            ->where('scholarships.data.0.show_url', route('scholarships.show', $program->slug)));
        $this->get(route('scholarships.show', $program->slug))->assertOk()->assertInertia(fn (Assert $page) => $page->where('studentWorkspace', false));
    }
}
