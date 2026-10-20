<?php

namespace Tests\Feature;

use App\Events\AccountNotificationsChanged;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\ApplicationActivity;
use App\Notifications\ProviderVerificationDecision;
use App\Services\AccountNotifications;
use App\Services\ApplicationWorkflow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_application_transitions_notify_only_the_relevant_recipient_and_stale_retries_do_not_duplicate(): void
    {
        $item = $this->draft();
        $provider = $item->scholarship->provider->user;
        $this->assertDatabaseCount('notifications', 0);
        $context = app(ApplicationWorkflow::class)->context($item->student, $item->scholarship);
        $data = ['version' => 0, 'context_token' => $context['context_token'], 'confirmed' => true];
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $provider->notifications()->count());
        $this->assertSame(0, $item->student->notifications()->count());
        $this->patch('/student/applications/'.$item->id.'/submit', $data)->assertSessionHasErrors('version');
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($provider)->patch('/provider/applications/'.$item->id, ['version' => 1, 'status' => 'under_review', 'reason' => 'Private student explanation', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $item->student->notifications()->count());
        $this->assertDatabaseCount('notifications', 2);
        $this->patch('/provider/applications/'.$item->id, ['version' => 1, 'status' => 'accepted', 'reason' => 'Private student explanation', 'confirmed' => true])->assertSessionHasErrors('version');
        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $notice = $item->student->notifications()->firstOrFail();
        $this->assertSame(['application_id', 'event', 'status'], array_keys($notice->data));
        $this->assertStringNotContainsString('Private student explanation', json_encode($notice->data));
    }

    public function test_transaction_rollback_removes_notification_and_application_transition(): void
    {
        $item = $this->draft();
        try {
            DB::transaction(function () use ($item): void {
                app(ApplicationWorkflow::class)->submit($item->student, $item, ['version' => 0,
                    'context_token' => app(ApplicationWorkflow::class)->context($item->student, $item->scholarship)['context_token'], 'confirmed' => true]);
                $this->assertDatabaseCount('notifications', 1);
                throw new \RuntimeException('Rollback fixture');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback fixture', $error->getMessage());
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($item->fresh()->submitted_at);
    }

    public function test_inbox_and_read_actions_are_scoped_and_untrusted_payload_fields_are_not_exposed(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $other = StudentProfile::factory()->create()->user;
        $own = $this->notice($student, ['url' => 'https://malicious.test', 'reason' => 'Sensitive details', 'password' => 'Secret fixture']);
        $foreign = $this->notice($other);
        $this->actingAs($student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.total', 1)->where('notifications.data.0.id', $own->id)->where('notifications.data.0.url', null)->missing('notifications.data.0.data'))->assertDontSee('Sensitive details')->assertDontSee('malicious.test')->assertDontSee('Secret fixture');
        $this->getJson('/notifications/summary')->assertExactJson(['unread' => 1])->assertHeader('Cache-Control', 'no-store, private');
        $this->patch('/notifications/'.$foreign->id.'/read')->assertNotFound();
        $this->patch('/notifications/'.$own->id.'/read')->assertSessionHasNoErrors();
        $readAt = $own->fresh()->read_at->toIso8601String();
        $this->travel(1)->minutes();
        $this->patch('/notifications/'.$own->id.'/read')->assertSessionHasNoErrors();
        $this->assertSame($readAt, $own->fresh()->read_at->toIso8601String());
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_read_all_requires_confirmation_changes_only_owner_and_keeps_new_notices_unread(): void
    {
        $owner = StudentProfile::factory()->create()->user;
        $other = StudentProfile::factory()->create()->user;
        $this->notice($owner);
        $foreign = $this->notice($other);
        $this->actingAs($owner)->patch('/notifications/read-all')->assertSessionHasErrors('confirmed');
        $this->assertSame(1, app(AccountNotifications::class)->summary($owner)['unread']);
        $this->patch('/notifications/read-all', ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(0, app(AccountNotifications::class)->summary($owner)['unread']);
        $this->assertNull($foreign->fresh()->read_at);
        $new = $this->notice($owner);
        $this->assertNull($new->read_at);
        $this->assertSame(1, app(AccountNotifications::class)->summary($owner)['unread']);
    }

    public function test_filters_pagination_unknown_types_and_guest_restrictions(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        $this->getJson('/notifications/summary')->assertUnauthorized();
        $student = StudentProfile::factory()->create()->user;
        for ($i = 0; $i < 12; $i++) {
            $this->notice($student);
        }
        $student->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'UnsupportedLegacyNotice', 'data' => ['secret' => 'Hidden']]);
        $this->actingAs($student)->get('/notifications?filter=unread')->assertInertia(fn (Assert $page) => $page->where('notifications.total', 12)->has('notifications.data', 10));
        $this->get('/notifications?filter=read')->assertInertia(fn (Assert $page) => $page->where('notifications.total', 0));
        $this->get('/notifications?filter=invalid')->assertSessionHasErrors('filter');
        $this->getJson('/notifications/summary')->assertExactJson(['unread' => 12]);
    }

    public function test_provider_verification_notices_remain_accessible_while_pending_and_application_links_recheck_access(): void
    {
        $profile = ProviderProfile::factory()->create(['verification_status' => 'pending']);
        $notice = new ProviderVerificationDecision($profile->id, 'rejected', 'Private reason', now()->toIso8601String());
        $profile->user->notifyNow($notice, ['database']);
        $this->actingAs($profile->user)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.total', 1)->where('notifications.data.0.url', route('provider.status'))->missing('notifications.data.0.reason'));
        $item = Application::factory()->create(['scholarship_id' => Scholarship::factory()->create(['provider_id' => $profile->id])->id]);
        $applicationNotice = $this->notice($profile->user, ['application_id' => $item->id]);
        $this->assertNull(app(AccountNotifications::class)->row($profile->user, $applicationNotice)['url']);
    }

    public function test_application_links_are_derived_from_authorized_records(): void
    {
        $item = $this->draft();
        $item->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        $studentNotice = $this->notice($item->student, ['application_id' => $item->id]);
        $provider = $item->scholarship->provider->user;
        $providerNotice = $this->notice($provider, ['application_id' => $item->id]);
        $service = app(AccountNotifications::class);
        $this->assertSame(route('student.applications.show', $item), $service->row($item->student, $studentNotice)['url']);
        $this->assertSame(route('provider.applications.show', $item), $service->row($provider, $providerNotice)['url']);
        $item->scholarship->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->assertNull($service->row($provider, $providerNotice)['url']);
        $item->delete();
        $this->assertSame(route('student.applications.show', $item), $service->row($item->student, $studentNotice)['url']);
    }

    public function test_queued_mail_is_generic_after_commit_and_rechecks_recipient_authorization(): void
    {
        $item = $this->draft();
        $item->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        $provider = $item->scholarship->provider->user;
        $notice = new ApplicationActivity($item->id, 'submitted', 'submitted', $provider->id, 'provider');
        $this->assertTrue($notice->afterCommit);
        $this->assertSame(['mail'], $notice->via($provider));
        $this->assertTrue($notice->shouldSend($provider, 'mail'));
        $mail = $notice->toMail($provider);
        $this->assertStringNotContainsString($item->student->name, implode(' ', $mail->introLines));
        $this->assertSame(route('notifications.index'), $mail->actionUrl);
        $item->scholarship->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->assertFalse($notice->shouldSend($provider, 'mail'));
        $this->assertFalse($notice->shouldSend($item->student, 'mail'));
    }

    public function test_private_channel_authorization_and_broadcast_payload_do_not_expose_notice_data(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $student = StudentProfile::factory()->create()->user;
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$student->id])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$other->id])->assertForbidden();
        $event = new AccountNotificationsChanged($student->id);
        $this->assertSame('private-App.Models.User.'.$student->id, $event->broadcastOn()[0]->name);
        $this->assertSame(['changed' => true], $event->broadcastWith());
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    private function notice(User $user, array $extra = []): DatabaseNotification
    {
        return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => ApplicationActivity::class, 'data' => ['application_id' => 999, 'event' => 'reviewed', 'status' => 'accepted', ...$extra]]);
    }

    private function draft(): Application
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);

        return app(ApplicationWorkflow::class)->start(StudentProfile::factory()->create()->user, $program);
    }
}
