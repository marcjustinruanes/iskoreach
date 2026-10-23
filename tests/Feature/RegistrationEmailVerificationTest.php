<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StudentEmailCode;
use App\Services\StudentAccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_work_email_requires_its_own_code_and_organization_email_remains_the_login(): void
    {
        $data = $this->providerRegistrationData();
        $this->withSession(['registration_work_email' => null]);
        $this->postJson('/register/validate-step', [...$data, 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors('work_email');
        $email = ['email' => $data['work_email'], 'account_type' => 'provider', 'registration_intent' => $data['registration_intent'], 'purpose' => 'work'];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertOk();
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertSessionMissing('registration_work_email');
        $provider = User::firstOrFail();
        $this->assertSame($data['organization_email'], $provider->email);
        $this->assertSame($data['work_email'], $provider->providerProfile->work_email);
    }

    public function test_work_email_code_cannot_verify_the_organization_email_even_when_the_addresses_match(): void
    {
        $data = $this->providerRegistrationData(['work_email' => 'office@gmail.com']);
        $this->withSession(['registration_email' => null, 'registration_work_email' => null]);
        $email = ['email' => $data['work_email'], 'account_type' => 'provider', 'registration_intent' => $data['registration_intent'], 'purpose' => 'work'];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertOk();
        $this->postJson('/register/email/verify', [...$email, 'purpose' => 'account', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post('/register', $data)->assertSessionHasErrors('organization_email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_work_email_verification_is_email_bound_and_expires_and_students_cannot_request_it(): void
    {
        $data = $this->providerRegistrationData();
        $this->postJson('/register/validate-step', [...$data, 'work_email' => 'changed@gmail.com', 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors('work_email');
        $this->travel(11)->minutes();
        $this->postJson('/register/validate-step', [...$data, 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors('work_email');
        $this->postJson('/register/email/send', ['email' => 'person@gmail.com', 'account_type' => 'student', 'purpose' => 'work'])->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_registration_rejects_non_gmail_addresses_before_sending_codes_or_creating_accounts(): void
    {
        Notification::fake();
        $provider = $this->providerRegistrationData();
        foreach (['person@yahoo.com', 'person@outlook.com', 'person@gmail.com.example.org', 'person@mail.gmail.com'] as $email) {
            foreach (['student', 'provider'] as $role) {
                $this->postJson('/register/email/send', ['email' => $email, 'account_type' => $role, 'registration_intent' => $role === 'provider' ? $provider['registration_intent'] : ''])
                    ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Use an @gmail.com email address.']);
            }
            $this->travel(11)->minutes();
        }
        $this->post('/register', $this->studentRegistrationData(['email' => 'person@yahoo.com']))->assertSessionHasErrors(['email' => 'Use an @gmail.com email address.']);
        $this->post('/register', [...$provider, 'organization_email' => 'person@outlook.com'])->assertSessionHasErrors(['organization_email' => 'Use an @gmail.com email address.']);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_reloading_registration_discards_an_existing_email_code_verification(): void
    {
        $data = $this->studentRegistrationData();
        $this->get('/register/student')->assertOk()->assertSessionMissing('registration_email');
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_an_existing_email_is_rejected_before_a_registration_code_is_sent(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'registered@gmail.com']);
        $data = $this->providerRegistrationData();
        foreach (['student', 'provider'] as $role) {
            $this->postJson('/register/email/send', ['email' => ' REGISTERED@Gmail.com ', 'account_type' => $role, 'registration_intent' => $role === 'provider' ? $data['registration_intent'] : ''])
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('accounts', 1);
    }

    /** @param array<string, mixed> $data */
    private function sendCode(array $data): string
    {
        Notification::fake();
        $this->postJson('/register/email/send', $data)->assertOk()->assertJsonPath('retry_after', 60);
        $code = '';
        Notification::assertSentOnDemand(StudentEmailCode::class, function (StudentEmailCode $notification, array $channels, object $notifiable) use (&$code, $data): bool {
            preg_match('/\b([0-9]{6})\b/', $notification->toMail($notifiable)->introLines[0], $match);
            $code = $match[1];

            return $notifiable->routes['mail'] === strtolower(trim($data['email'])) && $channels === ['mail'];
        });

        return $code;
    }

    public function test_registration_requires_an_email_code_and_consumes_the_verified_session(): void
    {
        $this->fakeRegistrationLocations();
        $data = $this->studentRegistrationData();
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
        $email = ['email' => $data['email'], 'account_type' => 'student'];
        $code = $this->sendCode($email);
        $this->assertArrayNotHasKey('code', session('registration_email'));
        $this->assertSame(64, strlen(session('registration_email.hash')));
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertOk()->assertJsonPath('verified', true);
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect('/login');
        $user = User::firstOrFail();
        $this->assertSame($data['email'], $user->email);
        $this->assertNull(session('registration_email'));
        Notification::assertCount(1);
    }

    public function test_a_code_cannot_verify_a_different_address_or_another_session(): void
    {
        $email = ['email' => 'owner@gmail.com', 'account_type' => 'student'];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/verify', [...$email, 'email' => 'other@gmail.com', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withSession(['registration_email' => null]);
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_expired_codes_and_expired_registration_proofs_are_rejected(): void
    {
        $this->freezeTime();
        $email = ['email' => 'owner@gmail.com', 'account_type' => 'student'];
        $code = $this->sendCode($email);
        $this->travel(11)->minutes();
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $data = $this->studentRegistrationData();
        $this->travel(11)->minutes();
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_resends_have_a_cooldown_and_attempt_limits_prevent_code_guessing(): void
    {
        config(['student-verification.code_attempts' => 2]);
        $email = ['email' => 'owner@gmail.com', 'account_type' => 'student'];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/send', $email)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/register/email/verify', [...$email, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/register/email/verify', [...$email, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        Notification::assertCount(1);
    }

    public function test_provider_uses_verified_organization_email_for_login_and_ignores_a_separate_account_email(): void
    {
        $data = $this->providerRegistrationData(['email' => 'ignored@gmail.com']);
        $this->withSession(['registration_email' => null]);
        $email = ['email' => $data['organization_email'], 'account_type' => 'provider', 'registration_intent' => $data['registration_intent']];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/verify', [...$email, 'code' => $code])->assertOk();
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $user = User::firstOrFail();
        $this->assertSame('office@gmail.com', $user->email);
        $this->assertSame($user->email, $user->providerProfile->contact_email);
        $this->post('/logout');
        $this->post('/login', ['email' => 'office@gmail.com', 'password' => 'provider-password'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_switching_email_after_verification_requires_a_new_code(): void
    {
        $data = $this->studentRegistrationData();
        $this->post('/register', [...$data, 'email' => 'replacement@gmail.com'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_a_student_verification_code_cannot_be_used_for_a_provider_account(): void
    {
        $provider = $this->providerRegistrationData();
        $this->withSession(['registration_email' => null]);
        $email = ['email' => $provider['organization_email'], 'account_type' => 'student'];
        $code = $this->sendCode($email);
        $this->postJson('/register/email/verify', [...$email, 'account_type' => 'provider', 'registration_intent' => $provider['registration_intent'], 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertFalse(session('registration_email.verified'));
    }

    public function test_organization_types_come_from_backend_options_and_unknown_types_are_rejected(): void
    {
        $data = $this->providerRegistrationData(['organization_type' => 'invented']);
        $this->get('/register/provider')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('registrationOptions.organizationTypes', 9)->where('registrationOptions.organizationTypes.0.value', 'foundation'));
        $this->post('/register', $data)->assertSessionHasErrors('organization_type');
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_delivery_failure_does_not_claim_success_or_create_a_verification_proof(): void
    {
        config(['mail.default' => 'unconfigured-mailer']);
        $this->postJson('/register/email/send', ['email' => 'owner@gmail.com', 'account_type' => 'student'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNull(session('registration_email'));
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_contacts_require_ten_digits_after_the_philippine_country_code(): void
    {
        foreach (['+63917123456', '+6391712345678', '+19171234567', '0917abc12345'] as $number) {
            $this->post('/register', $this->studentRegistrationData(['contact_number' => $number]))->assertSessionHasErrors('contact_number');
            $this->post('/register', $this->providerRegistrationData(['contact_number' => $number, 'organization_phone' => $number]))->assertSessionHasErrors(['contact_number', 'organization_phone']);
        }
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_local_contact_numbers_are_saved_in_international_format(): void
    {
        $this->post('/register', $this->studentRegistrationData(['contact_number' => '0917 123-4567']))->assertSessionHasNoErrors();
        $this->assertSame('+639171234567', User::firstOrFail()->studentProfile->contact_number);
        $this->post('/logout');
        $this->post('/register', $this->providerRegistrationData(['contact_number' => '9191234567', 'organization_phone' => '639181234567']))->assertSessionHasNoErrors();
        $profile = User::where('email', 'office@gmail.com')->firstOrFail()->providerProfile;
        $this->assertSame('+639191234567', $profile->contact_number);
        $this->assertSame('+639181234567', $profile->organization_phone);
        $this->actingAs($profile->user);
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => $profile->organization_name, 'contact_email' => $profile->contact_email, 'contact_number' => '09191234567', 'organization_phone' => '09201234567'])->assertSessionHasNoErrors();
        $this->assertSame('+639191234567', $profile->fresh()->contact_number);
        $this->assertSame('+639201234567', $profile->fresh()->organization_phone);
    }

    public function test_students_and_providers_can_explicitly_register_without_a_middle_name(): void
    {
        $this->post('/register', $this->studentRegistrationData(['middle_name' => '', 'no_middle_name' => true]))->assertSessionHasNoErrors();
        $student = User::firstOrFail();
        $this->assertNull($student->studentProfile->middle_name);
        $this->assertSame('Ana Cruz', $student->name);
        $student->studentProfile->forceFill(['registration_status' => 'approved'])->save();
        $this->actingAs($student->fresh());
        $this->patch('/student/profiles/'.$student->studentProfile->id, ['middle_name' => 'Maria', 'no_middle_name' => false])->assertSessionHasNoErrors();
        $this->patch('/student/profiles/'.$student->studentProfile->id, ['no_middle_name' => true])->assertSessionHasNoErrors();
        $this->assertNull($student->studentProfile->fresh()->middle_name);
        $this->assertTrue(collect(app(StudentAccountSetup::class)->data($student)['steps'])->firstWhere('key', 'personal')['complete']);
        $this->post('/logout');
        $this->post('/register', $this->providerRegistrationData(['middle_name' => 'Ignored', 'no_middle_name' => '1', 'contact_number' => '09181234567', 'organization_phone' => '09191234567']))->assertSessionHasNoErrors();
        $provider = User::where('email', 'office@gmail.com')->firstOrFail();
        $this->assertNull($provider->providerProfile->middle_name);
        $this->assertSame('Provider Contact', $provider->name);
        $profile = $provider->providerProfile;
        $this->actingAs($provider);
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => $profile->organization_name, 'contact_email' => $profile->contact_email, 'middle_name' => 'Maria', 'no_middle_name' => false])->assertSessionHasNoErrors();
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => $profile->organization_name, 'contact_email' => $profile->contact_email, 'no_middle_name' => true])->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->middle_name);
    }

    public function test_a_blank_middle_name_requires_the_explicit_no_middle_name_choice(): void
    {
        $this->post('/register', $this->studentRegistrationData(['middle_name' => '', 'no_middle_name' => false]))->assertSessionHasErrors('middle_name');
        $this->post('/register', $this->providerRegistrationData(['middle_name' => '', 'no_middle_name' => false]))->assertSessionHasErrors('middle_name');
        $this->assertDatabaseCount('accounts', 0);
    }
}
