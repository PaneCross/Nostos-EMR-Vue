<?php

// ─── ChatV2Test ──────────────────────────────────────────────────────────────
// End-to-end coverage for the Chat v2 feature set : role-group channels,
// auto-add by JobTitle, reactions, read receipts, edit window, soft-delete,
// pinning with override, mute / @mention override, mentions parsing,
// in-channel search, and audit logging.
//
// Plan reference : docs/plans/chat_v2_plan.md §10.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\ChatMessageMention;
use App\Models\ChatMessagePin;
use App\Models\ChatMessageReaction;
use App\Models\JobTitle;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChatV2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $admin;          // primary_care admin
    private User $rn;             // primary_care RN
    private User $rn2;             // primary_care RN
    private User $itAdmin;        // it_admin admin
    private User $exec;           // executive
    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id]);

        // Seed at least one JobTitle so @rn parsing can resolve.
        JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'rn', 'label' => 'Registered Nurse', 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'primary_care',
            'role' => 'admin',
            'job_title' => 'rn',
        ]);
        $this->rn = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'primary_care',
            'role' => 'standard',
            'job_title' => 'rn',
        ]);
        $this->rn2 = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'primary_care',
            'role' => 'standard',
            'job_title' => 'rn',
        ]);
        $this->itAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'it_admin',
            'role' => 'admin',
        ]);
        $this->exec = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'executive',
            'role' => 'standard',
        ]);

        $this->service = app(ChatService::class);
    }

    // ── Role-group channel creation ──────────────────────────────────────────

    public function test_dept_admin_can_create_role_group_for_their_department(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/chat/role-group-channels', [
                'name' => 'Primary Care RN huddle',
                'description' => 'Daily huddle for primary care RNs',
                'job_title_codes' => ['rn'],
                'departments' => ['primary_care'],
                'site_wide' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('channel.channel_type', 'role_group');

        $this->assertDatabaseHas('emr_chat_channels', ['name' => 'Primary Care RN huddle', 'channel_type' => 'role_group']);
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.channel_created']);
    }

    public function test_regular_user_cannot_create_role_group(): void
    {
        $this->actingAs($this->rn)
            ->postJson('/chat/role-group-channels', [
                'name' => 'whatever',
                'job_title_codes' => ['rn'],
                'departments' => ['primary_care'],
            ])
            ->assertForbidden();
    }

    public function test_dept_admin_cannot_create_site_wide_role_group(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/chat/role-group-channels', [
                'name' => 'All RNs',
                'job_title_codes' => ['rn'],
                'departments' => [],
                'site_wide' => true,
            ])
            ->assertForbidden();
    }

    public function test_executive_can_create_site_wide_role_group(): void
    {
        $this->actingAs($this->exec)
            ->postJson('/chat/role-group-channels', [
                'name' => 'All RNs site-wide',
                'job_title_codes' => ['rn'],
                'departments' => [],
                'site_wide' => true,
            ])
            ->assertCreated();
    }

    public function test_role_group_creation_auto_adds_matching_users(): void
    {
        $channel = $this->service->createRoleGroupChannel(
            $this->tenant->id, $this->admin,
            'Primary Care RNs', null, ['rn'], ['primary_care'], false,
        );

        $memberIds = $channel->memberships()->pluck('user_id')->all();
        // admin (rn), rn, rn2 should all auto-join.
        $this->assertContains($this->admin->id, $memberIds);
        $this->assertContains($this->rn->id, $memberIds);
        $this->assertContains($this->rn2->id, $memberIds);
        // exec (no rn job title) should NOT be in.
        $this->assertNotContains($this->exec->id, $memberIds);
    }

    // ── Auto-add observer ────────────────────────────────────────────────────

    public function test_observer_auto_adds_user_when_job_title_set(): void
    {
        // Create role-group BEFORE the new user has the job title.
        $channel = $this->service->createRoleGroupChannel(
            $this->tenant->id, $this->admin,
            'RNs', null, ['rn'], ['primary_care'], false,
        );

        $newbie = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'department' => 'primary_care',
            'role' => 'standard',
            'job_title' => null,
        ]);

        // Not a member yet.
        $this->assertFalse($channel->memberships()->where('user_id', $newbie->id)->exists());

        // Assign rn job_title : observer fires.
        $newbie->update(['job_title' => 'rn']);

        $this->assertTrue($channel->memberships()->where('user_id', $newbie->id)->exists());
        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'chat.member_added_by_role',
            'user_id' => $newbie->id,
        ]);
    }

    public function test_observer_auto_removes_user_when_role_changes_away(): void
    {
        $channel = $this->service->createRoleGroupChannel(
            $this->tenant->id, $this->admin,
            'RNs', null, ['rn'], ['primary_care'], false,
        );
        $this->assertTrue($channel->memberships()->where('user_id', $this->rn->id)->exists());

        $this->rn->update(['job_title' => null]);

        $this->assertFalse($channel->memberships()->where('user_id', $this->rn->id)->exists());
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.member_removed_by_role']);
    }

    // ── Reactions ────────────────────────────────────────────────────────────

    public function test_reaction_toggles_add_then_remove(): void
    {
        $channel = $this->service->createRoleGroupChannel(
            $this->tenant->id, $this->admin, 'RNs', null, ['rn'], ['primary_care'], false,
        );
        $message = ChatMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $this->admin->id,
            'message_text' => 'hi',
            'priority' => 'standard',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/react", ['reaction' => 'thumbs_up'])
            ->assertOk()
            ->assertJsonPath('action', 'added');

        $this->assertDatabaseHas('emr_chat_message_reactions', [
            'message_id' => $message->id, 'user_id' => $this->rn->id, 'reaction' => 'thumbs_up',
        ]);

        // Toggle off.
        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/react", ['reaction' => 'thumbs_up'])
            ->assertOk()
            ->assertJsonPath('action', 'removed');

        $this->assertDatabaseMissing('emr_chat_message_reactions', [
            'message_id' => $message->id, 'user_id' => $this->rn->id, 'reaction' => 'thumbs_up',
        ]);
    }

    public function test_unknown_reaction_code_rejected(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'x', 'priority' => 'standard', 'sent_at' => now(),
        ]);
        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/react", ['reaction' => 'fire'])
            ->assertStatus(422);
    }

    // ── Read receipts + audit ────────────────────────────────────────────────

    public function test_first_read_writes_audit_row_then_subsequent_reads_no_op(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'hi', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/read")
            ->assertOk();

        $this->assertEquals(1, AuditLog::where('action', 'chat.message_read')->where('resource_id', $message->id)->count());

        // Second read : idempotent, no new audit row.
        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/read")
            ->assertOk();
        $this->assertEquals(1, AuditLog::where('action', 'chat.message_read')->where('resource_id', $message->id)->count());
    }

    // ── Edit window ──────────────────────────────────────────────────────────

    public function test_sender_can_edit_within_5_minute_window(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'original', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/chat/channels/{$channel->id}/messages/{$message->id}", ['message_text' => 'edited'])
            ->assertOk();

        $message->refresh();
        $this->assertSame('edited', $message->message_text);
        $this->assertSame('original', $message->original_message_text);
        $this->assertNotNull($message->edited_at);
    }

    public function test_edit_outside_window_rejected(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'old', 'priority' => 'standard',
            'sent_at' => Carbon::now()->subMinutes(10),
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/chat/channels/{$channel->id}/messages/{$message->id}", ['message_text' => 'try again'])
            ->assertStatus(422);
    }

    // ── Soft delete ──────────────────────────────────────────────────────────

    public function test_sender_can_soft_delete_with_audit(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'gone', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/chat/channels/{$channel->id}/messages/{$message->id}")
            ->assertOk();

        $message->refresh();
        $this->assertTrue($message->trashed());
        $this->assertSame($this->admin->id, $message->deleted_by_user_id);
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.message_deleted']);
    }

    // ── Pin permissions + cap + override ────────────────────────────────────

    public function test_dept_admin_can_pin_in_their_role_group_channel(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        $message = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'important', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/chat/channels/{$channel->id}/messages/{$message->id}/pin")
            ->assertOk();

        $this->assertDatabaseHas('emr_chat_message_pins', ['message_id' => $message->id]);
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.message_pinned']);
    }

    public function test_pin_blocked_at_50_cap_unless_override(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);

        // Seed 50 pinned messages.
        for ($i = 0; $i < 50; $i++) {
            $m = ChatMessage::create([
                'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
                'message_text' => "msg $i", 'priority' => 'standard', 'sent_at' => now(),
            ]);
            ChatMessagePin::create(['channel_id' => $channel->id, 'message_id' => $m->id, 'pinned_by_user_id' => $this->admin->id, 'pinned_at' => now()]);
        }

        $extra = ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'one too many', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        // Without override : 422.
        $this->actingAs($this->admin)
            ->postJson("/chat/channels/{$channel->id}/messages/{$extra->id}/pin")
            ->assertStatus(422);

        // With override : 200 + override audit row.
        $this->actingAs($this->admin)
            ->postJson("/chat/channels/{$channel->id}/messages/{$extra->id}/pin", ['override' => true])
            ->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.pin_cap_override']);
    }

    // ── Mentions parsing ────────────────────────────────────────────────────

    public function test_mentions_parsed_for_all_four_forms(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);

        $this->actingAs($this->admin)
            ->postJson("/chat/channels/{$channel->id}/messages", [
                'message_text' => "Hey @{$this->rn->first_name}.{$this->rn->last_name} please page @rn @primary-care @all",
            ])
            ->assertCreated();

        $message = ChatMessage::where('channel_id', $channel->id)->latest('id')->first();
        $mentions = ChatMessageMention::where('message_id', $message->id)->get();

        // Should have one of each kind.
        $this->assertTrue($mentions->contains('mentioned_user_id', $this->rn->id));
        $this->assertTrue($mentions->contains('mentioned_role_code', 'rn'));
        $this->assertTrue($mentions->contains('mentioned_department', 'primary_care'));
        $this->assertTrue($mentions->contains('is_at_all', true));
    }

    // ── Mute ─────────────────────────────────────────────────────────────────

    public function test_mute_endpoint_creates_row(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);

        $this->actingAs($this->rn)
            ->postJson("/chat/channels/{$channel->id}/mute")
            ->assertOk();

        $this->assertDatabaseHas('emr_chat_channel_mutes', [
            'channel_id' => $channel->id, 'user_id' => $this->rn->id,
        ]);
    }

    // ── Group DM management ──────────────────────────────────────────────────

    public function test_any_user_can_create_group_dm_and_audit_row_logged(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'department' => 'finance']);

        $this->actingAs($this->rn)
            ->postJson('/chat/group-dm-channels', [
                'name' => 'Hangout',
                'member_user_ids' => [$this->admin->id, $other->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_chat_channels', ['channel_type' => 'group_dm', 'name' => 'Hangout']);
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.channel_created']);
    }

    public function test_group_dm_rename_audited(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'department' => 'finance']);
        $channel = $this->service->createGroupDmChannel($this->tenant->id, $this->rn, 'Old', [$this->admin->id, $other->id]);

        $this->actingAs($this->rn)
            ->patchJson("/chat/group-dm-channels/{$channel->id}", ['name' => 'New name'])
            ->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'chat.channel_renamed']);
    }

    // ── Search ──────────────────────────────────────────────────────────────

    public function test_in_channel_search_returns_matches(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);
        ChatMessage::create([
            'channel_id' => $channel->id, 'sender_user_id' => $this->admin->id,
            'message_text' => 'unique-fingerprint-abc', 'priority' => 'standard', 'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson("/chat/channels/{$channel->id}/search?q=fingerprint-abc")
            ->assertOk()
            ->assertJsonStructure(['matches' => [['id', 'message_text']]]);
    }

    // ── Channel Settings + audit timeline ───────────────────────────────────

    public function test_settings_endpoint_returns_channel_and_audit_timeline(): void
    {
        $channel = $this->service->createRoleGroupChannel($this->tenant->id, $this->admin, 'X', null, ['rn'], ['primary_care'], false);

        $this->actingAs($this->admin)
            ->getJson("/chat/channels/{$channel->id}/settings")
            ->assertOk()
            ->assertJsonStructure([
                'channel'        => ['id', 'name', 'targets', 'members'],
                'audit_timeline' => [['action', 'description', 'at']],
            ]);
    }
}
