<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApplicationDocument;
use App\Models\DocumentSubmission;
use App\Models\RenewalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowSimplificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_tables_are_absent_and_grants_and_submissions_have_their_new_names(): void
    {
        foreach (['application_status_histories', 'application_documents', 'renewal_documents', 'scholarship_awards', 'scholarship_declarations', 'scholarship_declaration_reviews', 'student_verifications', 'external_applicant_counts', 'password_reset_tokens', 'jobs', 'failed_jobs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('scholarship_grants'));
        $this->assertTrue(Schema::hasColumn('document_submissions', 'submission_type'));
    }

    public function test_shared_document_table_preserves_purpose_and_prevents_cross_workflow_downloads(): void
    {
        $application = ApplicationDocument::factory()->create();
        $renewal = RenewalDocument::factory()->create();
        $this->assertDatabaseCount('document_submissions', 2);
        $this->assertSame('new_application', $application->submission_type);
        $this->assertSame('renewal', $renewal->submission_type);
        $this->assertSame(1, ApplicationDocument::count());
        $this->assertSame(1, RenewalDocument::count());
        $this->actingAs($application->application->student)->get('/application-documents/'.$renewal->id.'/download')->assertNotFound();
        $this->actingAs($renewal->renewal->award->application->student)->get('/renewals/documents/'.$application->id)->assertNotFound();
    }

    public function test_document_consolidation_preserves_both_workflows_when_migrating_existing_records(): void
    {
        $application = ApplicationDocument::factory()->create();
        $renewal = RenewalDocument::factory()->create();
        $migration = require database_path('migrations/2026_10_22_181834_consolidate_documents_and_rename_scholarship_grants.php');
        $migration->down();
        $this->assertDatabaseHas('application_documents', ['application_id' => $application->application_id, 'student_document_id' => $application->student_document_id]);
        $this->assertDatabaseHas('renewal_documents', ['renewal_id' => $renewal->renewal_id, 'student_document_id' => $renewal->student_document_id]);
        $migration->up();
        $this->assertDatabaseCount('document_submissions', 2);
        $this->assertDatabaseHas('document_submissions', ['submission_type' => 'new_application', 'application_id' => $application->application_id, 'student_document_id' => $application->student_document_id]);
        $this->assertDatabaseHas('document_submissions', ['submission_type' => 'renewal', 'renewal_id' => $renewal->renewal_id, 'student_document_id' => $renewal->student_document_id]);
        $this->assertTrue(Schema::hasTable('scholarship_grants'));
    }

    public function test_a_submission_cannot_mix_an_application_and_a_renewal(): void
    {
        $application = ApplicationDocument::factory()->create();
        $renewal = RenewalDocument::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        DocumentSubmission::create(['submission_type' => 'new_application', 'application_id' => $application->application_id, 'renewal_id' => $renewal->renewal_id, 'student_document_id' => $application->student_document_id, 'version' => 2]);
    }

    public function test_retired_declaration_and_student_verification_routes_are_unavailable(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get('/declarations')->assertNotFound();
        $this->post('/declarations', [])->assertNotFound();
        $this->get('/admin/student-verifications')->assertNotFound();
        $this->post('/student/verification', [])->assertNotFound();
        $this->patch('/admin/external-counts/1', [])->assertNotFound();
    }
}
