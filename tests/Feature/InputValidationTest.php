<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\ProviderVerificationStatus;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\InputRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unicode_names_are_preserved_and_invalid_names_are_rejected(): void
    {
        foreach (["Ma. Jos\u{00e9} O\u{2019}Neill", 'Ana-Maria de la Cruz, Jr.', "Nin\u{0303}a", "\u{674e}\u{660e}", "\u{0915}\u{094d}\u{200d}\u{0937}", "Ana\u{2011}Maria"] as $name) {
            $this->assertTrue(Validator::make(['name' => $name], ['name' => InputRules::name()])->passes(), $name);
        }
        foreach (['123456', '---', "Ana\x01Cruz", 'Ana123', '<script>', str_repeat('a', 256), "Ana\nCruz"] as $name) {
            $this->assertTrue(Validator::make(['name' => $name], ['name' => InputRules::name()])->fails(), $name);
        }
        $this->assertTrue(Validator::make(['name' => str_repeat('a', 255)], ['name' => InputRules::name()])->passes());
    }

    public function test_direct_registration_normalizes_selected_text_without_trimming_passwords(): void
    {
        $password = '  valid password!  ';
        $user = app(CreateNewUser::class)->create(['name' => "\u{00a0}Ana Cruz\u{00a0}", 'email' => '  ANA+tag@Example.test  ',
            'password' => $password, 'password_confirmation' => $password, 'account_type' => 'provider', 'organization_name' => '  Foundation 123 & Co.  ']);
        $this->assertSame('Ana Cruz', $user->name);
        $this->assertSame('ana+tag@example.test', $user->email);
        $this->assertSame('Foundation 123 & Co.', $user->providerProfile->organization_name);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check(trim($password), $user->password));
    }

    public function test_registration_rejects_blank_names_and_case_variant_duplicate_emails(): void
    {
        User::factory()->create(['email' => 'Existing@Example.test']);
        $this->post('/register', ['name' => "\u{00a0}", 'email' => 'EXISTING@example.test', 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])
            ->assertSessionHasErrors(['name', 'email']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_rules_reject_internal_whitespace_and_incomplete_domains_without_dns_checks(): void
    {
        foreach (['person @example.test', 'person@localhost', "person\t@example.test", 'bad@@example.test', str_repeat('a', 256).'@example.test'] as $email) {
            $this->assertTrue(Validator::make(['email' => $email], ['email' => InputRules::email()])->fails());
        }
        $this->assertTrue(Validator::make(['email' => 'person+tag@example.test'], ['email' => InputRules::email()])->passes());
    }

    public function test_profile_information_action_ignores_own_email_but_rejects_another_account_case_variant(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        app(UpdateUserProfileInformation::class)->update($user, ['name' => '  Ana Cruz  ', 'email' => ' OWNER@Example.test ']);
        $this->assertSame('Ana Cruz', $user->fresh()->name);
        User::factory()->create(['email' => 'other@example.test']);
        $this->expectException(ValidationException::class);
        app(UpdateUserProfileInformation::class)->update($user, ['name' => 'Ana Cruz', 'email' => 'OTHER@Example.test']);
    }

    public function test_new_password_byte_limits_nulls_minimums_and_confirmation_are_consistent(): void
    {
        foreach ([str_repeat('a', 72), str_repeat("\u{00f1}", 36), '  pass word!  '] as $password) {
            $this->assertTrue(Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => InputRules::password()])->passes());
        }
        foreach ([str_repeat('a', 73), str_repeat("\u{00f1}", 37), "abcdefgh\0tail", 'short'] as $password) {
            $this->assertTrue(Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => InputRules::password()])->fails());
        }
        $this->assertTrue(Validator::make(['password' => 'abcdefgh', 'password_confirmation' => 'different'], ['password' => InputRules::password()])->fails());
        $this->assertTrue(Validator::make(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], ['password' => InputRules::password(12)])->fails());
        $this->assertTrue(Validator::make(['password' => 'abcdefghijkl', 'password_confirmation' => 'abcdefghijkl'], ['password' => InputRules::password(12)])->passes());
    }

    public function test_reset_rejects_oversized_password_without_changing_hash_then_preserves_spaces_on_success(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        try {
            app(ResetUserPassword::class)->reset($user, ['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)]);
            $this->fail('An oversized password was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('password', $error->errors());
        }
        $this->assertSame($hash, $user->fresh()->password);
        app(ResetUserPassword::class)->reset($user, ['password' => ' pass word! ', 'password_confirmation' => ' pass word! ']);
        $this->assertTrue(Hash::check(' pass word! ', $user->fresh()->password));
    }

    public function test_existing_long_passwords_can_still_log_in(): void
    {
        $password = str_repeat('a', 73);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_provider_cosmetic_email_edit_preserves_approval_and_real_change_requires_review(): void
    {
        $profile = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved, 'organization_name' => 'Foundation 123', 'contact_email' => 'Contact@Example.test']);
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, ['organization_name' => '  Foundation 123  ', 'contact_email' => ' CONTACT@example.test '])->assertSessionHasNoErrors();
        $this->assertSame(ProviderVerificationStatus::Approved, $profile->fresh()->verification_status);
        $this->assertSame('Contact@Example.test', $profile->fresh()->contact_email);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => 'Foundation 123', 'contact_email' => 'new@example.test'])->assertSessionHasNoErrors();
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_profiles_allow_real_organization_and_academic_punctuation_but_reject_controls(): void
    {
        $student = StudentProfile::factory()->create();
        $this->actingAs($student->user)->patch('/student/profiles/'.$student->id, ['school' => '  School #123 & College  ', 'course' => ' BS IT (4-year) ', 'year_level' => 12])->assertSessionHasNoErrors();
        $this->assertSame('School #123 & College', $student->fresh()->school);
        $this->patch('/student/profiles/'.$student->id, ['school' => "Bad\x01school", 'course' => "BS\nIT", 'year_level' => 13])->assertSessionHasErrors(['school', 'course', 'year_level']);
        $provider = ProviderProfile::factory()->create();
        $this->actingAs($provider->user)->patch('/provider/profiles/'.$provider->id, ['organization_name' => "Bad\x01organization", 'contact_email' => 'person @example.test'])->assertSessionHasErrors(['organization_name', 'contact_email']);
    }

    public function test_direct_json_registration_rejects_oversized_passwords_without_echoing_them(): void
    {
        $password = str_repeat('a', 73);
        $response = $this->postJson('/register', ['name' => 'Ana Cruz', 'email' => 'ana@example.test', 'password' => $password, 'password_confirmation' => $password]);
        $response->assertUnprocessable()->assertJsonValidationErrors('password')->assertDontSee($password);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_provider_contact_email_does_not_gain_account_uniqueness_requirements(): void
    {
        $first = ProviderProfile::factory()->create(['contact_email' => 'shared@example.test']);
        $second = ProviderProfile::factory()->create();
        $this->actingAs($second->user)->patch('/provider/profiles/'.$second->id, ['organization_name' => $second->organization_name, 'contact_email' => ' SHARED@Example.test '])->assertSessionHasNoErrors();
        $this->assertSame($first->contact_email, $second->fresh()->contact_email);
    }

    public function test_multibyte_description_limit_is_enforced_before_storage(): void
    {
        $profile = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        $payload = ['title' => 'Valid scholarship', 'description' => str_repeat("\u{1f393}", 16384), 'scholarship_type' => 'Merit', 'currency' => 'PHP', 'accepts_existing_scholars' => false,
            'locations' => [['scope' => 'nationwide']], 'requirements' => []];
        $this->actingAs($profile->user)->post('/provider/scholarships', $payload)->assertSessionHasErrors('description');
        $this->assertDatabaseCount('scholarships', 0);
        $payload['description'] = str_repeat("\u{1f393}", 16383).'abc';
        $this->post('/provider/scholarships', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('scholarships', ['description' => $payload['description']]);
    }
}
