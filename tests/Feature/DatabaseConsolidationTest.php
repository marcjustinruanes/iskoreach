<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProviderProfile;
use App\Notifications\ProviderVerificationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_schema_uses_accounts_and_has_no_unused_or_redundant_tables(): void
    {
        $this->assertTrue(Schema::hasTable('accounts'));
        foreach (['users', 'cache', 'cache_locks', 'sessions', 'job_batches', 'geographic_datasets', 'geographic_places', 'geographic_correspondences', 'message_attachments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    public function test_synchronous_notifications_do_not_require_queue_tables(): void
    {
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $profile = ProviderProfile::factory()->create();
        $profile->user->notify((new ProviderVerificationDecision($profile->id, 'approved', null, now()->toIso8601String()))->beforeCommit());
        $this->assertFalse(Schema::hasTable('jobs'));
        $this->assertFalse(Schema::hasTable('failed_jobs'));
    }

    public function test_attachment_migration_round_trip_preserves_file_integrity_and_message_contents(): void
    {
        $message = Message::factory()->create(['body' => 'Retain this message.']);
        $attachment = [
            'attachment_path' => 'chat/fixture.pdf', 'attachment_original_name' => 'fixture.pdf',
            'attachment_mime_type' => 'application/pdf', 'attachment_size_bytes' => 12,
            'attachment_checksum' => hash('sha256', 'fixture-data'),
        ];
        $message->forceFill($attachment)->save();
        $migration = require database_path('migrations/2026_10_22_174711_merge_message_attachments_into_messages.php');
        $migration->down();
        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id, 'uploaded_by' => $message->sender_id,
            'path' => $attachment['attachment_path'], 'checksum' => $attachment['attachment_checksum'],
        ]);
        $migration->up();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Retain this message.'] + $attachment);
        $this->assertFalse(Schema::hasTable('message_attachments'));
    }
}
