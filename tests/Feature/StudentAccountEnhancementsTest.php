<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ScholarshipRequirement;
use App\Models\StudentProfile;
use App\Models\StudentVerification;
use App\Models\User;
use App\Notifications\StudentEmailCode;
use App\Services\AccountNotifications;
use App\Services\PrivateDocuments;
use App\Services\StudentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentAccountEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('profile_pictures', ['url' => '/profile-pictures']);
    }

    private function candidate(): User
    {
        $profile = StudentProfile::factory()->create(collect($this->studentRegistrationData())->only(StudentRegistration::FIELDS)->all());
        $user = $profile->user;
        $this->actingAs($user)->post('/student/profile/photo', ['photo' => UploadedFile::fake()->image('photo.jpg', 100, 100)])->assertSessionHasNoErrors();
        foreach (array_keys(config('student-verification.documents')) as $kind) {
            $this->post('/student/verification/documents/'.$kind, ['file' => UploadedFile::fake()->image('evidence.png')])->assertSessionHasNoErrors();
        }

        return $user->fresh();
    }

    public function test_photo_policy_can_be_configured_without_automatically_granting_a_badge(): void
    {
        $user = $this->candidate();
        $user->studentProfile->forceFill(['photo_path' => null])->save();
        $this->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('accountSetup.verification.eligible', false));
        config(['student-verification.require_photo' => false]);
        $this->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('accountSetup.verification.eligible', true)->where('accountSetup.completed', 4));
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasNoErrors();
        $item = StudentVerification::firstOrFail();
        $this->assertSame('pending', $item->status);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/student-verifications/'.$item->id)->assertInertia(fn (Assert $page) => $page->where('verification.photo_url', null));
    }

    public function test_ordinary_setup_is_independent_of_optional_verification_and_progress_uses_saved_fields(): void
    {
        $user = StudentProfile::factory()->create(collect($this->studentRegistrationData())->only(StudentRegistration::FIELDS)->all())->user;
        $this->actingAs($user)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->where('accountSetup.completed', 4)->where('accountSetup.percentage', 100)->where('accountSetup.verification.status', 'not_applied')->where('accountSetup.verification.eligible', false));
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasErrors('verification');
        $this->assertDatabaseCount('student_verifications', 0);
        $this->get('/student/scholarships')->assertOk();
    }

    public function test_current_photos_are_publishable_repeatably_without_publishing_private_evidence(): void
    {
        $user = $this->candidate();
        $path = $user->studentProfile->photo_path;
        Storage::disk('profile_pictures')->delete($path);
        $this->artisan('profiles:publish-pictures')->assertSuccessful();
        $this->artisan('profiles:publish-pictures')->assertSuccessful();
        Storage::disk('profile_pictures')->assertExists($path);
        $this->assertCount(1, Storage::disk('profile_pictures')->allFiles());
        Storage::disk('documents')->delete($path);
        $this->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('accountSetup.photo_url', '/profile-pictures/'.$path));
    }

    public function test_email_code_page_is_available_with_student_navigation_and_preserves_validation_redirects(): void
    {
        $user = StudentProfile::factory()->create()->user;
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get('/student/email-code')->assertInertia(fn (Assert $page) => $page->component('EmailCode')->where('auth.user.role', 'student')->where('verified', false));
        $this->from('/student/email-code')->post('/student/email-code/verify', ['code' => 'wrong'])->assertRedirect('/student/email-code')->assertSessionHasErrors('code');
    }

    public function test_photo_validation_private_access_and_confirmed_removal(): void
    {
        $user = StudentProfile::factory()->create()->user;
        $this->actingAs($user)->post('/student/profile/photo', ['photo' => UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('photo');
        $this->post('/student/profile/photo', ['photo' => UploadedFile::fake()->image('good.png', 100, 100)])->assertSessionHasNoErrors();
        $path = $user->studentProfile->fresh()->photo_path;
        Storage::disk('documents')->assertExists($path);
        Storage::disk('profile_pictures')->assertExists($path);
        $this->get('/student/profile/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/student/profile/photo')->assertNotFound();
        $this->actingAs($user)->delete('/student/profile/photo', ['confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->delete('/student/profile/photo', ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNull($user->studentProfile->fresh()->photo_path);
        Storage::disk('documents')->assertMissing($path);
        Storage::disk('profile_pictures')->assertMissing($path);
    }

    public function test_verification_requires_authorized_review_supports_rejection_reapplication_and_revocation(): void
    {
        $user = $this->candidate();
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasNoErrors();
        $item = StudentVerification::firstOrFail();
        $this->assertSame('pending', $item->status);
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasErrors('verification');
        $this->patch('/admin/student-verifications/'.$item->id, ['status' => 'verified', 'reason' => 'Looks correct', 'confirmed' => true])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/student-verifications/'.$item->id.'/files/student_id')->assertOk();
        $this->patch('/admin/student-verifications/'.$item->id, ['status' => 'rejected', 'reason' => 'Need clearer evidence', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/student/verification', ['confirmed' => true])->assertSessionHasNoErrors();
        $latest = StudentVerification::latest('id')->firstOrFail();
        $this->actingAs($admin)->patch('/admin/student-verifications/'.$latest->id, ['status' => 'verified', 'reason' => 'Evidence reviewed', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('verified', $latest->fresh()->status);
        $this->assertDatabaseCount('student_verifications', 2);
        $this->actingAs($user)->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('accountSetup.verification.status', 'verified'));
        $this->get('/notifications')->assertOk();
        $this->actingAs($admin)->patch('/admin/student-verifications/'.$latest->id, ['status' => 'revoked', 'reason' => 'Enrollment no longer valid', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $latest->fresh()->status);
    }

    public function test_evidence_changes_invalidate_pending_requests_and_verified_badges(): void
    {
        $user = $this->candidate();
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasNoErrors();
        $item = StudentVerification::firstOrFail();
        $item->update(['status' => 'verified']);
        $this->post('/student/profile/photo', ['photo' => UploadedFile::fake()->image('new.jpg', 100, 100)])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $item->fresh()->status);
        Storage::disk('documents')->assertExists($item->snapshot['photo_path']);
        $this->post('/student/verification', ['confirmed' => true])->assertSessionHasNoErrors();
        $latest = StudentVerification::latest('id')->firstOrFail();
        $this->patch('/student/profiles/'.$user->studentProfile->id, ['school' => 'Updated university'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $latest->fresh()->status);
        $other = StudentProfile::factory()->create();
        $this->patch('/student/profiles/'.$other->id, ['school' => 'Hacked'])->assertForbidden();
        $this->get('/admin/student-verifications/'.$item->id.'/files/student_id')->assertForbidden();
    }

    private function deliveredCode(User $user): string
    {
        $code = '';
        Notification::assertSentTo($user, StudentEmailCode::class, function ($notice) use ($user, &$code): bool {
            preg_match('/\b[0-9]{6}\b/', $notice->toMail($user)->introLines[0], $matches);
            $code = $matches[0];

            return true;
        });

        return $code;
    }

    public function test_email_codes_are_private_rate_limited_single_use_and_bound_to_the_authenticated_email(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = StudentProfile::factory()->create()->user;
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->post('/student/email-code/send')->assertSessionHasNoErrors();
        $code = $this->deliveredCode($user);
        $this->assertNotSame($code, $user->fresh()->email_code_hash);
        $this->post('/student/email-code/send')->assertSessionHasErrors('code');
        $other = StudentProfile::factory()->create()->user;
        $other->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($other)->post('/student/email-code/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->actingAs($user)->post('/student/email-code/verify', ['code' => $code])->assertRedirect(route('student.profile').'#verification');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull($user->fresh()->email_code_hash);
        $this->assertArrayNotHasKey('email_code_hash', $user->fresh()->toArray());
    }

    public function test_expired_and_exhausted_codes_cannot_verify_and_log_mailers_never_receive_codes(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $user = StudentProfile::factory()->create()->user;
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->post('/student/email-code/send')->assertSessionHasErrors('code');
        Notification::assertNothingSent();
        config(['mail.default' => 'smtp']);
        $this->post('/student/email-code/send')->assertSessionHasNoErrors();
        $code = $this->deliveredCode($user);
        $this->travel(11)->minutes();
        $this->post('/student/email-code/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::fake();
        $this->post('/student/email-code/send')->assertSessionHasNoErrors();
        $code = $this->deliveredCode($user);
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/student/email-code/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post('/student/email-code/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_requirements_are_application_specific_and_rejected_documents_can_be_corrected_without_overwriting_history(): void
    {
        $application = Application::factory()->create(['status' => 'under_review', 'submitted_at' => now()]);
        $application->scholarship->provider->forceFill(['verification_status' => 'approved'])->save();
        $rule = ScholarshipRequirement::factory()->create(['scholarship_id' => $application->scholarship_id, 'allowed_mime_types' => ['image/png'], 'max_size_kb' => 1024, 'requirement_stage' => 'application']);
        $other = Application::factory()->create();
        $this->actingAs($other->student)->post('/student/applications/'.$application->id.'/requirements/'.$rule->id.'/upload', ['file' => UploadedFile::fake()->image('proof.png'), 'version' => 0, 'confirmed' => true])->assertForbidden();
        $this->actingAs($application->student)->get('/student/requirements')->assertOk()->assertInertia(fn (Assert $page) => $page->has('applications.data', 1)->where('applications.data.0.checklist.0.name', $rule->name));
        $this->post('/student/applications/'.$application->id.'/requirements/'.$rule->id.'/upload', ['file' => UploadedFile::fake()->image('proof.png'), 'version' => 0, 'confirmed' => true])->assertSessionHasErrors('file');
        $document = app(PrivateDocuments::class)->upload($application->student, UploadedFile::fake()->image('old.png'), 'Old');
        $attachment = ApplicationDocument::create(['application_id' => $application->id, 'scholarship_requirement_id' => $rule->id, 'student_document_id' => $document->id, 'version' => 1, 'checklist_completed_at' => now(), 'requirement_snapshot' => $rule->toArray()]);
        $attachment->forceFill(['submitted_at' => now(), 'review_status' => 'rejected', 'review_notes' => 'Please upload a clearer copy'])->save();
        $this->post('/student/applications/'.$application->id.'/requirements/'.$rule->id.'/upload', ['file' => UploadedFile::fake()->image('proof.png'), 'version' => 0, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('application_documents', 2);
        $this->assertNotNull($attachment->fresh()->removed_at);
        $new = ApplicationDocument::latest('id')->firstOrFail();
        $this->assertNotNull($new->submitted_at);
        $this->assertSame(2, $new->version);
        $provider = $application->scholarship->provider->user;
        $notice = $provider->notifications()->latest()->firstOrFail();
        $this->assertSame(route('provider.applications.show', $application), app(AccountNotifications::class)->row($provider, $notice)['url']);
        $this->post('/student/applications/'.$application->id.'/requirements/'.$rule->id.'/upload', ['file' => UploadedFile::fake()->image('proof.png'), 'version' => 0, 'confirmed' => true])->assertSessionHasErrors('file');
    }
}
