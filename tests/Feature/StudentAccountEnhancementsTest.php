<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ScholarshipRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AccountNotifications;
use App\Services\PrivateDocuments;
use App\Services\StudentRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        return $user->fresh();
    }

    public function test_ordinary_setup_is_independent_of_optional_verification_and_progress_uses_saved_fields(): void
    {
        $user = StudentProfile::factory()->create(collect($this->studentRegistrationData())->only(StudentRegistration::FIELDS)->all())->user;
        $this->actingAs($user)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->where('accountSetup.completed', 3)->where('accountSetup.percentage', 100)->missing('accountSetup.verification'));
        $this->post('/student/verification', ['confirmed' => true])->assertNotFound();
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
        $this->assertDatabaseCount('document_submissions', 2);
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
