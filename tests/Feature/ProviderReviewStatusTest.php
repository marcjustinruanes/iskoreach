<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Notifications\ProviderVerificationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProviderReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_check_returns_latest_owner_scoped_pending_rejected_and_approved_results(): void
    {
        $profile = ProviderProfile::factory()->create();
        ProviderProfile::factory()->create(['verification_status' => 'rejected', 'rejection_reason' => 'Private reason for another provider']);
        $this->actingAs($profile->user)->getJson('/provider/status')->assertOk()->assertJsonPath('verification_status', 'pending')->assertJsonPath('rejection_reason', null)->assertDontSee('Private reason for another provider');
        $profile->forceFill(['verification_status' => 'rejected', 'rejection_reason' => 'Please correct the organization name.'])->save();
        $this->actingAs($profile->user->fresh())->getJson('/provider/status')->assertOk()->assertJsonPath('verification_status', 'rejected')->assertJsonPath('rejection_reason', 'Please correct the organization name.');
        $profile->forceFill(['verification_status' => 'approved', 'rejection_reason' => null])->save();
        $this->actingAs($profile->user->fresh())->getJson('/provider/status')->assertOk()->assertJsonPath('verification_status', 'approved')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/provider/status')->assertRedirect('/provider/dashboard');
        $this->actingAs(User::factory()->create(['role' => 'student']))->getJson('/provider/status')->assertForbidden();
    }

    public function test_admin_decisions_send_email_notifications_and_corrections_are_submitted_for_review(): void
    {
        Notification::fake();
        $profile = ProviderProfile::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('admin.providers.review', $profile), ['expected_version' => 0, 'verification_status' => 'rejected', 'rejection_reason' => 'Correct your organization details.'])->assertSessionHasNoErrors();
        Notification::assertSentTo($profile->user, ProviderVerificationDecision::class, fn ($notice, $channels): bool => $notice->status === 'rejected' && $notice->reason === 'Correct your organization details.' && in_array('mail', $channels, true));
        $this->actingAs($profile->user->fresh())->patch('/provider/profiles/'.$profile->id, ['organization_name' => 'Corrected Foundation', 'contact_email' => $profile->contact_email])->assertSessionHasNoErrors();
        $this->assertSame('pending', $profile->fresh()->verification_status->value);
        $this->assertNull($profile->fresh()->rejection_reason);
        $this->actingAs($admin)->patch(route('admin.providers.review', $profile), ['expected_version' => $profile->fresh()->verification_version, 'verification_status' => 'approved'])->assertSessionHasNoErrors();
        Notification::assertSentTo($profile->user, ProviderVerificationDecision::class, fn ($notice, $channels): bool => $notice->status === 'approved' && in_array('mail', $channels, true));
        $message = (new ProviderVerificationDecision($profile->id, 'rejected', 'Correct your organization details.', now()->toIso8601String()))->toMail($profile->user);
        $this->assertContains('Reason: Correct your organization details.', $message->introLines);
    }
}
