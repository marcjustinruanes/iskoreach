<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_work_email_requires_gmail_and_normalizes_case(): void
    {
        $data = $this->providerRegistrationData(['work_email' => 'representative@outlook.com']);
        $this->postJson('/register/validate-step', [...$data, 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors(['work_email' => 'Use an @gmail.com email address.']);
        $this->post('/register', $data)->assertSessionHasErrors(['work_email' => 'Use an @gmail.com email address.']);
        $this->assertDatabaseCount('accounts', 0);
        $this->post('/register', $this->withVerifiedRegistrationEmail([...$data, 'work_email' => ' REPRESENTATIVE@GMAIL.COM ']))->assertSessionHasNoErrors();
        $this->assertSame('representative@gmail.com', User::firstOrFail()->providerProfile->work_email);
    }

    public function test_organization_details_and_configured_type_specific_documents_are_validated_and_saved(): void
    {
        Notification::fake();
        config(['provider-verification.documents_by_type.government.required' => ['organization_proof', 'accreditation']]);
        $data = $this->providerRegistrationData(['organization_type' => 'government']);
        $this->post('/register', $data)->assertSessionHasErrors('accreditation');
        $data['accreditation'] = UploadedFile::fake()->image('mandate.jpg');
        $this->post('/register', [...$data, 'organization_description' => null])->assertSessionHasErrors('organization_description');
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $this->assertSame('PH', $profile->country);
        $this->assertSame('Test Barangay', $profile->barangay_name);
        $this->assertSame($data['organization_description'], $profile->organization_description);
        $this->assertArrayHasKey('accreditation', $profile->verification_documents);
        $profile->forceFill(['verification_status' => 'suspended', 'rejection_reason' => 'Documents require investigation'])->save();
        $this->actingAs($profile->user)->post(route('provider-verification.update', $profile), ['expected_version' => 0, 'representative_id' => UploadedFile::fake()->image('replacement.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $profile->fresh()->verification_status->value);
        $this->assertSame('Documents require investigation', $profile->fresh()->rejection_reason);
    }

    public function test_provider_registration_stores_separate_details_private_files_and_pending_status(): void
    {
        Notification::fake();
        $data = $this->providerRegistrationData(['middle_name' => 'Maria', 'suffix' => 'Jr.', 'role' => 'admin', 'verification_status' => 'approved']);
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $profile = $user->providerProfile;
        $this->assertSame('Provider Maria Contact Jr.', $user->name);
        $this->assertSame(UserRole::Provider, $user->role);
        $this->assertSame('Provider', $profile->first_name);
        $this->assertSame('work@gmail.com', $profile->work_email);
        $this->assertSame('office@gmail.com', $profile->contact_email);
        $this->assertSame('Test City', $profile->city_name);
        $this->assertSame('pending', $profile->verification_status->value);
        foreach ($profile->verification_documents as $document) {
            Storage::disk('documents')->assertExists($document['path']);
        }
        $this->get('/provider/workspace')->assertForbidden();
        $this->actingAs($user->fresh())->get('/provider/workspace')->assertForbidden();
        $this->get(route('provider-verification.show', [$profile, 'representative_id']))->assertDownload('representative_id.jpg')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_individual_administrators_require_administration_evidence_instead_of_organization_proof(): void
    {
        $data = $this->providerRegistrationData(['organization_type' => 'individual', 'organization_name' => null, 'organization_proof' => null]);
        $this->post('/register', $data)->assertSessionHasErrors('administration_evidence');
        $this->assertDatabaseCount('accounts', 0);
        $data['administration_evidence'] = UploadedFile::fake()->image('evidence.png');
        $data['organization_proof'] = UploadedFile::fake()->image('not-needed.png');
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $this->assertSame('Provider Maria Contact', ProviderProfile::firstOrFail()->organization_name);
        $this->assertArrayNotHasKey('organization_proof', ProviderProfile::firstOrFail()->verification_documents);
    }

    public function test_provider_rejects_missing_authorization_unsafe_uploads_and_missing_agreement(): void
    {
        $data = $this->providerRegistrationData(['authorization_required' => true, 'representative_id' => UploadedFile::fake()->create('script.php', 1, 'text/plain'), 'terms_accepted' => false]);
        $this->post('/register', $data)->assertSessionHasErrors(['authorization_letter', 'representative_id', 'terms_accepted']);
        $this->assertDatabaseCount('accounts', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $data = $this->providerRegistrationData(['representative_id' => UploadedFile::fake()->create('large.pdf', 1025, 'application/pdf')]);
        $this->post('/register', $data)->assertSessionHasErrors('representative_id');
    }

    public function test_registration_role_intent_cannot_be_changed_forged_or_reused_in_another_session(): void
    {
        $data = $this->providerRegistrationData();
        $data['account_type'] = 'student';
        $this->post('/register', $data)->assertSessionHasErrors('account_type');
        $data['account_type'] = 'provider';
        $data['registration_intent'] = 'forged';
        $this->post('/register', $data)->assertSessionHasErrors('account_type');
        $data = $this->providerRegistrationData();
        $this->withSession(['registration_nonce' => 'another-session']);
        $this->post('/register', $data)->assertSessionHasErrors('account_type');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_duplicate_email_creates_neither_provider_nor_private_files(): void
    {
        User::factory()->create(['email' => 'office@gmail.com']);
        $data = $this->providerRegistrationData(['email' => 'PROVIDER@gmail.com']);
        $this->post('/register', $data)->assertSessionHasErrors('organization_email');
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_step_validation_only_validates_the_current_step_and_never_creates_an_account(): void
    {
        $this->fakeRegistrationLocations();
        $this->postJson('/register/validate-step', ['account_type' => 'student', 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'surname', 'date_of_birth', 'contact_number', 'email'])->assertJsonMissingValidationErrors(['school']);
        $data = $this->studentRegistrationData(['step' => 1]);
        unset($data['password'], $data['school']);
        $this->postJson('/register/validate-step', $data)->assertOk()->assertJson(['valid' => true]);
        $this->postJson('/register/validate-step', $this->studentRegistrationData(['step' => 3, 'barangay_code' => '999999999']))->assertUnprocessable()->assertJsonValidationErrors('barangay_code');
        $this->postJson('/register/validate-step', $this->studentRegistrationData(['step' => 4, 'password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $data = $this->providerRegistrationData(['step' => 2]);
        unset($data['representative_id'], $data['organization_proof'], $data['password']);
        $this->postJson('/register/validate-step', $data)->assertOk();
        $data['step'] = 3;
        $this->postJson('/register/validate-step', $data)->assertUnprocessable()->assertJsonValidationErrors(['representative_id', 'organization_proof']);
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_private_verification_documents_deny_other_providers_students_and_guests(): void
    {
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $url = route('provider-verification.show', [$profile, 'representative_id']);
        $this->post('/logout');
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(ProviderProfile::factory()->create()->user)->get($url)->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get($url)->assertOk();
        Storage::disk('documents')->put($profile->verification_documents['representative_id']['path'], 'tampered');
        $this->get($url)->assertStatus(409);
    }

    public function test_admin_cannot_approve_until_required_documents_are_verified_and_review_confirmed(): void
    {
        Notification::fake();
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $url = route('admin.providers.review', $profile);
        $decision = ['verification_status' => 'approved', 'expected_version' => 0, 'documents_reviewed' => true];
        $this->actingAs($admin);
        $this->patch($url, array_replace($decision, ['documents_reviewed' => false]))->assertSessionHasErrors('verification_status');
        $path = $profile->verification_documents['organization_proof']['path'];
        $original = Storage::disk('documents')->get($path);
        Storage::disk('documents')->put($path, 'tampered');
        $this->patch($url, $decision)->assertSessionHasErrors('verification_status');
        Storage::disk('documents')->put($path, $original);
        $this->patch($url, $decision)->assertSessionHasNoErrors();
        $this->assertTrue($profile->fresh()->isApproved());
        $this->actingAs($profile->user->fresh())->get('/provider/workspace')->assertOk();
    }

    public function test_replacement_documents_return_provider_to_pending_and_reject_unauthorized_or_stale_updates(): void
    {
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $profile->forceFill(['verification_status' => 'approved'])->save();
        $url = route('provider-verification.update', $profile);
        $this->actingAs(ProviderProfile::factory()->create()->user)->post($url, ['expected_version' => 0])->assertForbidden();
        $this->actingAs($profile->user->fresh())->post($url, ['expected_version' => 9, 'representative_id' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasErrors('expected_version');
        $this->post($url, ['expected_version' => 0, 'representative_id' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('pending', $profile->fresh()->verification_status->value);
        $this->assertSame(1, $profile->fresh()->verification_version);
        $this->get('/provider/workspace')->assertForbidden();
    }
}
