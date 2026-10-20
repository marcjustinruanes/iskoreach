<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\PrivateDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivateDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_vault_upload_listing_download_and_removal_are_owner_scoped(): void
    {
        $this->get('/student/documents')->assertRedirect('/login');
        $student = StudentProfile::factory()->create()->user;
        $document = $this->upload($student);
        $this->get('/student/documents')->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->missing('documents.data.0.path')->missing('documents.data.0.checksum'));
        $this->get('/student/documents/'.$document->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->get('/student/documents/'.$document->id.'/download')->assertForbidden();
        $this->delete('/student/documents/'.$document->id, ['confirmed' => true])->assertForbidden();
        $this->get('/student/documents')->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
        foreach (['provider', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]))->post('/student/documents', ['label' => 'Secret', 'file' => $this->pdf()])->assertForbidden();
            $this->get('/student/documents/'.$document->id.'/download')->assertForbidden();
        }
    }

    public function test_upload_validates_content_extensions_size_and_label(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student);
        $bad = UploadedFile::fake()->createWithContent('fake.pdf', '<html>not a PDF</html>');
        $this->post('/student/documents', ['label' => 'Text disguised as PDF', 'file' => new UploadedFile($bad->getRealPath(), 'fake.pdf', null, null, true)])->assertSessionHasErrors('file');
        $this->post('/student/documents', ['label' => 'Wrong extension', 'file' => UploadedFile::fake()->createWithContent('script.html', "%PDF-1.4\nFixture")])->assertSessionHasErrors('file');
        $this->post('/student/documents', ['file' => $this->pdf()])->assertSessionHasErrors('label');
        $oversize = UploadedFile::fake()->create('huge.pdf', app(PrivateDocuments::class)->uploadLimitKilobytes() + 1, 'application/pdf');
        $this->post('/student/documents', ['label' => 'Too large', 'file' => $oversize])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('student_documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_valid_png_and_pdf_uploads_use_private_random_paths_and_selected_metadata(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $pdf = $this->upload($student);
        $this->assertStringNotContainsString('enrollment.pdf', $pdf->path);
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertSame(hash('sha256', "%PDF-1.4\nIskoReach private fixture\n%%EOF"), $pdf->checksum);
        $png = UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJXkAAAAASUVORK5CYII='));
        $this->post('/student/documents', ['label' => 'Photo', 'file' => $png])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('student_documents', 2);
        $this->assertCount(2, Storage::disk('documents')->allFiles());
        $this->get('/student/documents?q=Photo')->assertInertia(fn (Assert $page) => $page->where('documents.total', 1)->where('documents.data.0.mime_type', 'image/png'));
    }

    public function test_required_attachment_confirmation_unlocks_submission_and_provider_download_only_after_submission(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasErrors('application');
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id])->assertSessionHasErrors('confirmed');
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $attachment = ApplicationDocument::firstOrFail();
        $this->assertNotNull($attachment->checklist_completed_at);
        $this->actingAs($item->scholarship->provider->user)->get('/application-documents/'.$attachment->id.'/download')->assertForbidden();
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
        $this->assertNotNull($attachment->fresh()->submitted_at);
        $this->actingAs($item->scholarship->provider->user)->get('/application-documents/'.$attachment->id.'/download')->assertOk();
        $this->get('/provider/applications/'.$item->id)->assertInertia(fn (Assert $page) => $page->has('application.documents', 1)->missing('application.documents.0.path'));
        $this->assertSame(1, $item->fresh()->workflow_version - 1);
    }

    public function test_other_students_providers_and_other_program_requirements_cannot_be_attached(): void
    {
        [$item,$rule] = $this->draft();
        $other = StudentProfile::factory()->create()->user;
        $file = $this->upload($other);
        $this->actingAs($item->student)->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $own = $this->upload($item->student);
        $otherRule = ScholarshipRequirement::factory()->create();
        $this->post($this->url($item, $otherRule), ['version' => 0, 'document_id' => $own->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $rule->update(['requirement_stage' => 'renewal']);
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $own->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $this->actingAs($other)->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_requirement_size_and_format_are_checked_for_vault_reuse(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $rule->update(['allowed_mime_types' => ['image/png']]);
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $rule->update(['allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 0]);
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_replacement_retains_versions_and_only_freezes_the_selected_version(): void
    {
        [$item,$rule] = $this->draft();
        $first = $this->upload($item->student);
        $this->attach($item, $rule, $first);
        $second = $this->upload($item->student);
        $this->attach($item->fresh(), $rule, $second);
        $rows = ApplicationDocument::orderBy('version')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows->pluck('version')->all());
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
        $this->assertNull($rows[0]->fresh()->submitted_at);
        $this->assertNotNull($rows[1]->fresh()->submitted_at);
        $this->actingAs($item->scholarship->provider->user)->get('/application-documents/'.$rows[0]->id.'/download')->assertForbidden();
        $this->get('/application-documents/'.$rows[1]->id.'/download')->assertOk();
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/application-documents/'.$rows[1]->id.'/download')->assertForbidden();
    }

    public function test_vault_removal_preserves_submitted_files_but_prevents_new_reuse(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $this->attach($item, $rule, $file);
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
        $attachment = ApplicationDocument::firstOrFail();
        $this->delete('/student/documents/'.$file->id, ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($file);
        Storage::disk('documents')->assertExists($file->path);
        $this->get('/student/documents/'.$file->id.'/download')->assertNotFound();
        $this->get('/application-documents/'.$attachment->id.'/download')->assertOk();
        $this->actingAs($item->scholarship->provider->user)->get('/application-documents/'.$attachment->id.'/download')->assertOk();
        [$next,$nextRule] = $this->draft($item->student);
        $this->actingAs($item->student)->post($this->url($next, $nextRule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
    }

    public function test_detaching_latest_does_not_fall_back_to_an_older_version_and_stale_changes_fail(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $this->attach($item, $rule, $file);
        $this->post($this->url($item, $rule), ['version' => 0, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('version');
        $this->attach($item->fresh(), $rule, $file);
        $this->delete($this->url($item, $rule), ['version' => 2, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasErrors('application');
        $this->assertFalse(app(PrivateDocuments::class)->checklist($item->fresh())[0]['complete']);
        $this->attach($item->fresh(), $rule, $file);
        $this->assertSame(3, ApplicationDocument::latest('id')->firstOrFail()->version);
    }

    public function test_changed_or_missing_blobs_and_changed_confirmation_context_cannot_be_submitted(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $old = $this->submission($item);
        $this->attach($item, $rule, $file);
        $this->patch('/student/applications/'.$item->id.'/submit', [...$old, 'version' => 1])->assertSessionHasErrors('context_token');
        Storage::disk('documents')->put($file->path, 'changed content');
        $this->get('/student/documents/'.$file->id.'/download')->assertNotFound();
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasErrors('application');
        Storage::disk('documents')->delete($file->path);
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasErrors('application');
        $this->get('/student/documents/'.$file->id.'/download')->assertNotFound();
        $this->assertNull($item->fresh()->submitted_at);
    }

    public function test_submitted_attachments_are_immutable_and_requirement_edits_preserve_draft_file_history(): void
    {
        [$item,$rule] = $this->draft();
        $file = $this->upload($item->student);
        $this->attach($item, $rule, $file);
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
        $this->post($this->url($item, $rule), ['version' => 2, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasErrors('document_id');
        $this->delete($this->url($item, $rule), ['version' => 2, 'confirmed' => true])->assertSessionHasErrors('document_id');
        [$draft,$draftRule] = $this->draft($item->student);
        $this->attach($draft, $draftRule, $file);
        $attachment = ApplicationDocument::latest('id')->firstOrFail();
        $draftRule->delete();
        $this->assertNull($attachment->fresh()->scholarship_requirement_id);
        $this->assertSame('Enrollment proof', $attachment->fresh()->requirement_snapshot['name']);
        $this->get('/application-documents/'.$attachment->id.'/download')->assertOk();
        $this->assertSame([], app(PrivateDocuments::class)->checklist($draft->fresh()));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('enrollment.pdf', "%PDF-1.4\nIskoReach private fixture\n%%EOF");
    }

    private function upload(User $student): StudentDocument
    {
        $this->actingAs($student)->post('/student/documents', ['label' => 'Enrollment proof', 'file' => $this->pdf()])->assertSessionHasNoErrors();

        return StudentDocument::latest('id')->firstOrFail();
    }

    private function draft(?User $student = null): array
    {
        $student ??= StudentProfile::factory()->create()->user;
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $rule = ScholarshipRequirement::factory()->create(['scholarship_id' => $program->id, 'name' => 'Enrollment proof', 'required' => true, 'requirement_stage' => 'application', 'allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 1024]);

        return [app(ApplicationWorkflow::class)->start($student, $program), $rule];
    }

    private function attach(Application $item, ScholarshipRequirement $rule, StudentDocument $file): void
    {
        $this->actingAs($item->student)->post($this->url($item, $rule), ['version' => $item->workflow_version, 'document_id' => $file->id, 'confirmed' => true])->assertSessionHasNoErrors();
    }

    private function submission(Application $item): array
    {
        return ['version' => $item->workflow_version, 'context_token' => app(ApplicationWorkflow::class)->context($item->student, $item->scholarship)['context_token'], 'confirmed' => true];
    }

    private function url(Application $item, ScholarshipRequirement $rule): string
    {
        return '/student/applications/'.$item->id.'/requirements/'.$rule->id;
    }
}
