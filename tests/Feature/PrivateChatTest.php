<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\ChatChanged;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\ChatActivity;
use App\Services\AccountNotifications;
use App\Services\PrivateChat;
use App\Services\PrivateDocuments;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivateChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_conversation_search_is_scoped_to_the_authenticated_participant(): void
    {
        $thread = Conversation::factory()->create();
        $thread->student->update(['name' => 'Distinct Scholar']);
        Conversation::factory()->create(['student_id' => $thread->student_id]);
        $this->actingAs($thread->provider->user)->get('/messages?q=Distinct')->assertInertia(fn (Assert $page) => $page->where('threads.total', 1)->where('threads.data.0.id', $thread->id)->where('filters.q', 'Distinct'));
        $this->get('/messages?q=Unmatched')->assertInertia(fn (Assert $page) => $page->where('threads.total', 0));
        $this->get('/messages?'.http_build_query(['q' => str_repeat('x', 121)]))->assertSessionHasErrors('q');
    }

    public function test_conversations_start_only_from_owned_submitted_applications_and_are_unique_per_pair(): void
    {
        $profile = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $application = Application::factory()->create(['scholarship_id' => Scholarship::factory()->create(['provider_id' => $profile->id])->id]);
        $this->actingAs($application->student)->post('/messages/applications/'.$application->id)->assertSessionHasNoErrors();
        $thread = Conversation::firstOrFail();
        $this->post('/messages/applications/'.$application->id)->assertRedirect(route('messages.show', $thread));
        $this->actingAs($profile->user)->post('/messages/applications/'.$application->id)->assertRedirect(route('messages.show', $thread));
        $this->assertDatabaseCount('conversations', 1);
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->post('/messages/applications/'.$application->id)->assertForbidden();
        $application->update(['submitted_at' => null, 'status' => 'not_started']);
        $this->actingAs($application->student)->post('/messages/applications/'.$application->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/messages')->assertForbidden();
    }

    public function test_index_state_and_mutations_deny_nonparticipants_and_guests(): void
    {
        $thread = Conversation::factory()->create();
        $this->get('/messages')->assertRedirect('/login');
        $this->actingAs($thread->student)->get('/messages')->assertInertia(fn (Assert $page) => $page->where('threads.total', 1));
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->get('/messages')->assertInertia(fn (Assert $page) => $page->where('threads.total', 0));
        foreach (['/messages/'.$thread->id, '/messages/'.$thread->id.'/state'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->post('/messages/'.$thread->id, $this->payload())->assertForbidden();
        $this->postJson('/messages/'.$thread->id.'/typing', ['typing' => true])->assertForbidden();
        $this->postJson('/messages/'.$thread->id.'/read', ['through_id' => 1])->assertForbidden();
    }

    public function test_sending_uses_authenticated_sender_and_retries_do_not_duplicate_messages_or_notices(): void
    {
        $thread = Conversation::factory()->create();
        $payload = [...$this->payload('A private question'), 'sender_id' => $thread->provider->user_id, 'conversation_id' => 999, 'read_at' => now()];
        $this->actingAs($thread->student)->post('/messages/'.$thread->id, $payload)->assertSessionHasNoErrors();
        $this->post('/messages/'.$thread->id, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
        $message = Message::firstOrFail();
        $this->assertSame($thread->student_id, $message->sender_id);
        $this->assertNull($message->read_at);
        $this->assertSame($thread->id, $message->conversation_id);
        $this->post('/messages/'.$thread->id, [...$payload, 'body' => 'Changed retry'])->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->post('/messages/'.$thread->id, $this->payload('0'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('messages', ['body' => '0']);
    }

    public function test_empty_invalid_and_oversized_messages_or_files_are_rejected(): void
    {
        $thread = Conversation::factory()->create();
        $this->actingAs($thread->student)->post('/messages/'.$thread->id, ['submission_token' => (string) Str::uuid()])->assertSessionHasErrors(['body', 'file']);
        $this->post('/messages/'.$thread->id, $this->payload(str_repeat('a', 5001)))->assertSessionHasErrors('body');
        $bad = UploadedFile::fake()->createWithContent('bad.pdf', '<html>not PDF</html>');
        $this->post('/messages/'.$thread->id, ['submission_token' => (string) Str::uuid(), 'file' => new UploadedFile($bad->getRealPath(), 'bad.pdf', null, null, true)])->assertSessionHasErrors('file');
        $this->post('/messages/'.$thread->id, ['submission_token' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('large.pdf', app(PrivateDocuments::class)->uploadLimitKilobytes() + 1, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_private_attachments_are_downloadable_only_by_current_participants_and_retries_cleanup_extra_blobs(): void
    {
        $thread = Conversation::factory()->create();
        $token = (string) Str::uuid();
        $this->actingAs($thread->student)->post('/messages/'.$thread->id, ['submission_token' => $token, 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $this->post('/messages/'.$thread->id, ['submission_token' => $token, 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertCount(1, Storage::disk('documents')->allFiles());
        $attachment = MessageAttachment::firstOrFail();
        $this->get('/messages/attachments/'.$attachment->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($thread->provider->user)->get('/messages/attachments/'.$attachment->id)->assertOk();
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/messages/attachments/'.$attachment->id)->assertForbidden();
        $this->actingAs($thread->student)->get('/messages/'.$thread->id)->assertInertia(fn (Assert $page) => $page->missing('messages.data.0.attachment.path')->missing('messages.data.0.submission_token'));
        Storage::disk('documents')->put($attachment->path, 'tampered');
        $this->get('/messages/attachments/'.$attachment->id)->assertNotFound();
    }

    public function test_read_receipts_are_bounded_do_not_mark_own_messages_and_mark_only_related_chat_notices(): void
    {
        $thread = Conversation::factory()->create();
        $service = app(PrivateChat::class);
        $first = $service->send($thread->student, $thread, $this->payload('One'), null);
        $own = $service->send($thread->provider->user, $thread, $this->payload('Reply'), null);
        $later = $service->send($thread->student, $thread, $this->payload('Later'), null);
        $this->actingAs($thread->provider->user)->postJson('/messages/'.$thread->id.'/read', ['through_id' => $first->id])->assertOk();
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($own->fresh()->read_at);
        $this->assertNull($later->fresh()->read_at);
        $this->assertSame(1, app(AccountNotifications::class)->summary($thread->provider->user)['unread']);
        $other = Message::factory()->create();
        $this->postJson('/messages/'.$thread->id.'/read', ['through_id' => $other->id])->assertUnprocessable();
    }

    public function test_typing_is_participant_scoped_temporary_and_contains_no_typed_text(): void
    {
        $thread = Conversation::factory()->create();
        $this->actingAs($thread->student)->postJson('/messages/'.$thread->id.'/typing', ['typing' => true, 'body' => 'Private unsent content'])->assertOk();
        $this->getJson('/messages/'.$thread->id.'/state')->assertJsonPath('typing', false);
        $this->actingAs($thread->provider->user)->getJson('/messages/'.$thread->id.'/state')->assertJsonPath('typing', true)->assertDontSee('Private unsent content');
        $this->travel(6)->seconds();
        $this->getJson('/messages/'.$thread->id.'/state')->assertJsonPath('typing', false);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_provider_reverification_revokes_existing_chat_state_sending_downloads_and_notifications_links(): void
    {
        $thread = Conversation::factory()->create();
        $this->actingAs($thread->student)->post('/messages/'.$thread->id, ['submission_token' => (string) Str::uuid(), 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $attachment = MessageAttachment::firstOrFail();
        $notice = $thread->provider->user->notifications()->firstOrFail();
        $thread->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->getJson('/messages/'.$thread->id.'/state')->assertForbidden();
        $this->post('/messages/'.$thread->id, $this->payload())->assertForbidden();
        $this->get('/messages/attachments/'.$attachment->id)->assertForbidden();
        $this->get('/messages')->assertInertia(fn (Assert $page) => $page->where('threads.total', 0));
        $this->assertNull(app(AccountNotifications::class)->row($thread->provider->user, $notice)['url']);
    }

    public function test_message_pagination_and_safe_preview_data_and_parent_history_survive(): void
    {
        $thread = Conversation::factory()->create();
        Message::factory()->count(27)->create(['conversation_id' => $thread->id, 'sender_id' => $thread->student_id]);
        $this->actingAs($thread->student)->getJson('/messages/'.$thread->id.'/state')->assertJsonPath('messages.total', 27)->assertJsonCount(25, 'messages.data')->assertJsonPath('messages.next_page_url', route('messages.show', $thread).'?page=2');
        $this->get('/messages/'.$thread->id.'?page=2')->assertInertia(fn (Assert $page) => $page->has('messages.data', 2));
        $this->get('/messages/'.$thread->id)->assertDontSee($thread->provider->contact_email);
        $service = app(PrivateChat::class);
        $service->send($thread->student, $thread, $this->payload('Secret chat body'), null);
        $notice = $thread->provider->user->notifications()->firstOrFail();
        $this->assertSame(ChatActivity::class, $notice->type);
        $this->assertArrayNotHasKey('body', $notice->data);
        $row = app(AccountNotifications::class)->row($thread->provider->user, $notice);
        $this->assertStringNotContainsString('Secret chat body', json_encode($row));
        $this->assertSame(route('messages.show', $thread), $row['url']);
    }

    public function test_private_chat_channel_auth_denies_outsiders_and_event_payload_is_only_a_signal(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $thread = Conversation::factory()->create();
        $data = ['socket_id' => '123.456', 'channel_name' => 'private-chat.'.$thread->id];
        $this->actingAs($thread->student)->postJson('/broadcasting/auth', $data)->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($thread->provider->user)->postJson('/broadcasting/auth', $data)->assertOk();
        $this->actingAs(StudentProfile::factory()->create()->user)->postJson('/broadcasting/auth', $data)->assertForbidden();
        $event = new ChatChanged($thread->id);
        $this->assertSame(['changed' => true], $event->broadcastWith());
        $this->assertSame('private-chat.'.$thread->id, $event->broadcastOn()[0]->name);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    private function payload(string $body = 'A private chat message'): array
    {
        return ['body' => $body, 'submission_token' => (string) Str::uuid()];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('chat-proof.pdf', "%PDF-1.4\nPrivate chat fixture\n%%EOF");
    }
}
