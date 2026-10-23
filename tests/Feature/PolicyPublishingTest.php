<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\RegistrationPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PolicyPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_modal_uses_only_the_latest_published_policy_and_rejects_unknown_kinds(): void
    {
        $original = app(RegistrationPolicies::class)->published('terms');
        $draft = PolicyDocument::factory()->create(['kind' => 'terms', 'title' => 'New terms', 'content' => 'Unpublished policy draft', 'published_at' => null]);

        $this->getJson('/register/policies/terms')->assertOk()->assertJsonPath('version', $original->version())->assertJsonPath('content', $original->content)->assertJsonMissing(['content' => $draft->content]);
        $draft->forceFill(['published_at' => now()])->save();
        $this->getJson('/register/policies/terms')->assertOk()->assertJsonPath('version', $draft->version())->assertJsonPath('content', $draft->content);
        $this->getJson('/register/policies/privacy')->assertOk()->assertJsonPath('policy', 'privacy');
        $this->getJson('/register/policies/unknown')->assertNotFound();
    }

    public function test_only_admins_can_edit_registration_policies(): void
    {
        $this->get('/admin/policies')->assertRedirect('/login');
        $student = User::factory()->create();
        $this->actingAs($student)->get('/admin/policies')->assertForbidden();
        $this->post('/admin/policies/privacy', [])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/policies')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/Policies')->has('documents', 2));
    }

    public function test_drafts_do_not_change_public_policies_until_an_admin_publishes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $original = app(RegistrationPolicies::class)->published('privacy');
        $content = trim(str_repeat('Updated privacy information for students and providers. ', 4));
        $this->actingAs($admin)->post('/admin/policies/privacy', ['title' => 'Updated privacy notice', 'content' => $content, 'action' => 'draft', 'expected_version' => $original->id])->assertSessionHasNoErrors();
        $draft = PolicyDocument::latest('id')->firstOrFail();
        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page->where('version', $original->version())->where('title', $original->title));
        $this->post('/admin/policies/privacy', ['title' => $draft->title, 'content' => $draft->content, 'action' => 'publish', 'expected_version' => $draft->id])->assertSessionHasNoErrors();
        $published = PolicyDocument::latest('id')->firstOrFail();
        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page->where('version', $published->version())->where('content', $content));
        $this->assertNotNull($published->published_at);
        $this->assertDatabaseHas('policy_documents', ['id' => $original->id, 'content' => $original->content]);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $admin->id, 'action' => 'policy.publish', 'subject_id' => $published->id]);
    }

    public function test_registration_rejects_policy_versions_published_after_the_form_was_opened(): void
    {
        $data = $this->studentRegistrationData();
        $this->fakeRegistrationLocations();
        PolicyDocument::factory()->create(['kind' => 'terms', 'published_at' => now()]);
        $this->post('/register', $data)->assertSessionHasErrors('terms_version');
        $this->assertDatabaseCount('accounts', 0);
        $this->getJson('/register/policies')->assertOk()->assertJsonPath('terms_version', 'terms-3');
    }

    public function test_earlier_acceptance_records_are_preserved_after_publishing(): void
    {
        $policies = app(RegistrationPolicies::class);
        $oldTerms = $policies->published('terms');
        $user = User::factory()->create(['terms_policy_id' => $oldTerms->id, 'privacy_policy_id' => $policies->published('privacy')->id, 'registration_policy_version' => 'terms-1|privacy-2']);
        PolicyDocument::factory()->create(['kind' => 'terms', 'published_at' => now()]);
        $this->assertSame($oldTerms->id, $user->fresh()->terms_policy_id);
        $this->assertSame('terms-1|privacy-2', $user->fresh()->registration_policy_version);
        $this->assertSame('terms-3', $policies->versions()['terms_version']);
    }

    public function test_policy_validation_and_stale_editor_protection_prevent_unreviewed_overwrites(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->post('/admin/policies/terms', ['title' => '', 'content' => 'short', 'action' => 'publish', 'expected_version' => 1])->assertSessionHasErrors(['title', 'content']);
        PolicyDocument::factory()->create(['kind' => 'terms']);
        $this->post('/admin/policies/terms', ['title' => 'New terms', 'content' => str_repeat('A sufficient policy explanation. ', 5), 'action' => 'publish', 'expected_version' => 1])->assertSessionHasErrors('expected_version');
        $this->assertSame('terms-1', app(RegistrationPolicies::class)->published('terms')->version());
    }

    public function test_published_privacy_notice_explains_philippine_law_and_main_administrator_requests(): void
    {
        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page->where('content', fn (string $content): bool => str_contains($content, 'Republic Act No. 10173') && str_contains($content, 'main system administrator') && str_contains($content, 'National Privacy Commission')));
    }
}
