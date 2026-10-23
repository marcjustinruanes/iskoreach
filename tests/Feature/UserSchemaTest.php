<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_challenges_and_acceptance_timestamps_are_not_user_columns(): void
    {
        foreach (['email_verified_at', 'remember_token', 'requires_email_verification', 'terms_accepted_at', 'privacy_accepted_at', 'email_code_hash', 'email_code_expires_at', 'email_code_sent_at', 'email_code_attempts'] as $column) {
            $this->assertFalse(Schema::hasColumn('accounts', $column), $column);
        }
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame($role, $user->fresh()->role);
        }
    }

    public function test_database_rejects_unsupported_roles_without_model_casts(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('accounts')->where('id', $user->id)->update(['role' => 'superadmin']);
    }

    public function test_login_logout_and_password_reset_work_without_a_remember_token_column(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/reset-password', ['token' => Password::createToken($user), 'email' => $user->email, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }

    public function test_obsolete_post_registration_email_verification_endpoints_are_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/email/verify')->assertNotFound();
        $this->get('/student/email-code')->assertNotFound();
        $this->post('/student/email-code/send')->assertNotFound();
        $this->post('/student/email-code/verify', ['code' => '123456'])->assertNotFound();
    }
}
