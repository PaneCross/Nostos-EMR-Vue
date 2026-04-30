<?php

// ─── ChatController ───────────────────────────────────────────────────────────
// Handles the chat API (JSON) and the Inertia page render.
//
// All API endpoints require the authenticated user to be a member of the
// channel being accessed (enforced by requireMembership()).
//
// Routes:
//   GET  /chat                          → index()         Inertia page
//   GET  /chat/users/search?q={term}    → searchUsers()   JSON: tenant user search (DM typeahead)
//   GET  /chat/channels                 → channels()      JSON: my channels + unread counts
//   GET  /chat/channels/{id}/messages   → messages()      JSON: paginated messages
//   POST /chat/channels/{id}/messages   → send()          JSON: new message + Reverb broadcast
//   POST /chat/channels/{id}/read       → markRead()      JSON: mark last_read_at = now()
//   POST /chat/direct/{userId}          → directMessage() JSON: get/create DM channel
//   GET  /chat/unread-count             → unreadCount()   JSON: total unread across all channels
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\ChannelMembersChanged;
use App\Events\ChatActivityEvent;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\MessagePinned;
use App\Events\MessageReacted;
use App\Events\MessageRead;
use App\Events\MessageUnpinned;
use App\Events\NewChatMessage;
use App\Models\AuditLog;
use App\Models\ChatChannel;
use App\Models\ChatChannelMute;
use App\Models\ChatMessage;
use App\Models\ChatMessagePin;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService  $chatService,
        private readonly AlertService $alertService,
    ) {}

    // ── Inertia page ──────────────────────────────────────────────────────────

    /**
     * Render the /chat Inertia page.
     * Initial channel list is passed as a prop to avoid an extra round-trip.
     */
    public function index(Request $request): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => 'chat.view',
            'resource_type' => 'chat',
            'resource_id'   => null,
            'tenant_id'     => $user->effectiveTenantId(),
            'ip_address'    => $request->ip(),
        ]);

        return Inertia::render('Chat/Index');
    }

    // ── API endpoints (JSON) ──────────────────────────────────────────────────

    /**
     * Search users within the current tenant for the DM user-search typeahead.
     * Requires at least 2 characters. Returns max 20 results, excluding self.
     * Used by the frontend "New Message" DM search field in Chat/Index.tsx.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['users' => []]);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        $results = User::where('tenant_id', $user->effectiveTenantId())
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $like = '%' . strtolower($q) . '%';
                $query->whereRaw("LOWER(first_name) LIKE ?", [$like])
                      ->orWhereRaw("LOWER(last_name) LIKE ?", [$like])
                      ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", [$like]);
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'department', 'role'])
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->first_name . ' ' . $u->last_name,
                'department' => $u->department,
                'role'       => $u->role,
            ]);

        return response()->json(['users' => $results]);
    }

    /**
     * List all channels the authenticated user belongs to, grouped by type,
     * with unread message counts.
     */
    public function channels(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $channels = ChatChannel::active()
            ->forUser($user)
            ->with([
                'memberships' => fn ($q) => $q->where('user_id', $user->id),
                'mutes'       => fn ($q) => $q->where('user_id', $user->id),
                'roleTargets',
                'departmentTargets',
            ])
            ->orderBy('channel_type')
            ->orderBy('name')
            ->get()
            ->map(function (ChatChannel $ch) use ($user) {
                $membership = $ch->memberships->first();
                $mute       = $ch->mutes->first();
                $unreadQuery = $ch->messages()
                    ->withoutTrashed()
                    ->when($membership?->last_read_at, fn ($q) => $q->where('sent_at', '>', $membership->last_read_at));

                $unread       = (clone $unreadQuery)->count();
                $urgentUnread = (clone $unreadQuery)->where('priority', 'urgent')->count();

                // Unread @mentions of THIS user since their last read.
                $unreadMentions = (clone $unreadQuery)
                    ->whereHas('mentions', fn ($q) => $q->where('mentioned_user_id', $user->id))
                    ->count();

                return [
                    'id'                  => $ch->id,
                    'channel_type'        => $ch->channel_type,
                    'name'                => $ch->displayName($user),
                    'description'         => $ch->description,
                    'site_wide'           => $ch->site_wide,
                    'unread_count'        => $unread,
                    'urgent_unread_count' => $urgentUnread,
                    'unread_mentions_count' => $unreadMentions,
                    'is_active'           => $ch->is_active,
                    'is_muted'            => $mute && ($mute->snoozed_until === null || $mute->snoozed_until->isFuture()),
                    'snoozed_until'       => $mute?->snoozed_until?->toIso8601String(),
                    'targets'             => $ch->channel_type === 'role_group' ? [
                        'roles'       => $ch->roleTargets->pluck('job_title_code')->all(),
                        'departments' => $ch->departmentTargets->pluck('department')->all(),
                        'site_wide'   => $ch->site_wide,
                    ] : null,
                ];
            });

        return response()->json(['channels' => $channels]);
    }

    /**
     * Paginated message history for a channel (newest first).
     * Page size = 50. Soft-deleted messages included with is_deleted=true.
     */
    public function messages(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        /** @var \App\Models\User $viewer */
        $viewer = $request->user();

        $messages = $channel->messages()
            ->withTrashed()
            ->with(['sender', 'reactions', 'reads', 'pin', 'mentions'])
            ->orderBy('sent_at', 'desc')
            ->paginate(50)
            ->through(fn (ChatMessage $m) => $m->toApiArray($viewer));

        return response()->json([
            'messages'     => $messages->items(),
            'current_page' => $messages->currentPage(),
            'last_page'    => $messages->lastPage(),
        ]);
    }

    /**
     * Send a message to a channel and broadcast via Reverb.
     * If priority=urgent, creates a critical severity alert for all non-sender
     * channel members, with metadata.channel_id for deep-link navigation to
     * /chat?channel={id} from the notification bell or critical banner.
     */
    public function send(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $data = $request->validate([
            'message_text' => ['required', 'string', 'max:4000'],
            'priority'     => ['sometimes', 'in:standard,urgent'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $message = ChatMessage::create([
            'channel_id'     => $channel->id,
            'sender_user_id' => $user->id,
            'message_text'   => $data['message_text'],
            'priority'       => $data['priority'] ?? 'standard',
            'sent_at'        => Carbon::now(),
        ]);

        $message->load('sender');

        // Parse @mentions out of message_text and store rows for the four
        // mention forms (user / role / dept / all). Mentions ride alongside
        // the broadcast so subscribers can render highlights immediately.
        $this->chatService->parseAndStoreMentions($message);
        $message->load(['mentions', 'reactions', 'reads', 'pin']);

        // Broadcast message to all channel members via Reverb
        broadcast(new NewChatMessage($message));

        // Notify each non-sender member's personal channel so their nav badge updates
        $channel->memberships()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->each(fn ($memberId) => broadcast(new ChatActivityEvent($memberId, $channel->id)));

        // Urgent messages create a critical alert so all channel members see a
        // full-width banner. metadata.channel_id enables deep-link to the channel.
        if (($data['priority'] ?? 'standard') === 'urgent') {
            $memberDepts = $channel->memberships()
                ->where('user_id', '!=', $user->id)
                ->join('shared_users', 'emr_chat_memberships.user_id', '=', 'shared_users.id')
                ->pluck('shared_users.department')
                ->unique()
                ->values()
                ->toArray();

            if (! empty($memberDepts)) {
                $this->alertService->create([
                    'tenant_id'          => $user->effectiveTenantId(),
                    'source_module'      => 'chat',
                    'alert_type'         => 'urgent_chat_message',
                    'title'              => 'Urgent message from ' . $user->first_name . ' ' . $user->last_name,
                    'message'            => mb_strimwidth($data['message_text'], 0, 200, '…'),
                    'severity'           => 'critical',
                    'target_departments' => $memberDepts,
                    'created_by_system'  => false,
                    'created_by_user_id' => $user->id,
                    'metadata'           => ['channel_id' => $channel->id],
                ]);
            }
        }

        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => 'chat.message.send',
            'resource_type' => 'chat_message',
            'resource_id'   => $message->id,
            'tenant_id'     => $user->effectiveTenantId(),
            'ip_address'    => $request->ip(),
        ]);

        return response()->json(['message' => $message->toApiArray($user)], 201);
    }

    /**
     * Mark the channel as read for the authenticated user (update last_read_at).
     */
    public function markRead(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $channel->memberships()
            ->where('user_id', $user->id)
            ->update(['last_read_at' => Carbon::now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Get or create a direct-message channel with another user.
     * Both users must be in the same tenant.
     */
    public function directMessage(Request $request, User $user): JsonResponse
    {
        /** @var \App\Models\User $viewer */
        $viewer = $request->user();

        if ($viewer->effectiveTenantId() !== $user->effectiveTenantId()) {
            abort(403, 'Cross-tenant DMs are not permitted.');
        }

        if ($viewer->id === $user->id) {
            abort(422, 'Cannot start a DM with yourself.');
        }

        $channel = $this->chatService->getOrCreateDmChannel($viewer, $user, $viewer->effectiveTenantId());

        return response()->json([
            'channel' => [
                'id'           => $channel->id,
                'channel_type' => $channel->channel_type,
                'name'         => $channel->displayName($viewer),
            ],
        ]);
    }

    /**
     * Total unread message count across all channels the user belongs to.
     * Used for the notification badge in the nav bar.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $total = 0;

        $memberships = $user->chatMemberships()
            ->with('channel')
            ->get();

        foreach ($memberships as $membership) {
            if (! $membership->channel?->is_active) {
                continue;
            }

            $query = $membership->channel
                ->messages()
                ->withoutTrashed();

            if ($membership->last_read_at) {
                $query->where('sent_at', '>', $membership->last_read_at);
            }

            $total += $query->count();
        }

        return response()->json(['unread_count' => $total]);
    }

    // ── Chat v2 : role-group channel management ──────────────────────────────

    /**
     * Create a specialized (role-group) channel. Permissioned per
     * docs/plans/chat_v2_plan.md §11.2.
     */
    public function createRoleGroup(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:120'],
            'description'           => ['nullable', 'string', 'max:500'],
            'job_title_codes'       => ['required', 'array', 'min:1'],
            'job_title_codes.*'     => ['string', 'max:60'],
            'departments'           => ['present', 'array'],
            'departments.*'         => ['string', 'max:30'],
            'site_wide'             => ['sometimes', 'boolean'],
        ]);

        $siteWide = (bool) ($data['site_wide'] ?? false);
        $departments = $data['departments'];

        // Permission gate : section 11.2 of the plan.
        $this->requireRoleGroupCreatePermission($user, $departments, $siteWide);

        $channel = $this->chatService->createRoleGroupChannel(
            $user->effectiveTenantId(),
            $user,
            $data['name'],
            $data['description'] ?? null,
            $data['job_title_codes'],
            $departments,
            $siteWide,
        );

        return response()->json([
            'channel' => [
                'id'           => $channel->id,
                'channel_type' => $channel->channel_type,
                'name'         => $channel->name,
                'description'  => $channel->description,
                'site_wide'    => $channel->site_wide,
            ],
        ], 201);
    }

    public function updateRoleGroup(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->authorizeChannelManage($request->user(), $channel);
        if ($channel->channel_type !== 'role_group') {
            abort(404);
        }

        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:120'],
            'description'       => ['nullable', 'string', 'max:500'],
            'job_title_codes'   => ['sometimes', 'array', 'min:1'],
            'job_title_codes.*' => ['string', 'max:60'],
            'departments'       => ['sometimes', 'array'],
            'departments.*'     => ['string', 'max:30'],
            'site_wide'         => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($channel, $data, $request) {
            if (array_key_exists('name', $data)) {
                $channel->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $channel->description = $data['description'];
            }
            if (array_key_exists('site_wide', $data)) {
                $channel->site_wide = (bool) $data['site_wide'];
            }
            $channel->save();

            if (array_key_exists('job_title_codes', $data)) {
                $channel->roleTargets()->delete();
                foreach ($data['job_title_codes'] as $code) {
                    $channel->roleTargets()->create(['job_title_code' => $code]);
                }
            }
            if (array_key_exists('departments', $data)) {
                $channel->departmentTargets()->delete();
                if (! $channel->site_wide) {
                    foreach ($data['departments'] as $dept) {
                        $channel->departmentTargets()->create(['department' => $dept]);
                    }
                }
            }

            // Re-resolve membership : add new matchers, remove non-matchers.
            $newMemberIds = $this->chatService->resolveRoleGroupMembers(
                $channel->tenant_id,
                $channel->roleTargets()->pluck('job_title_code')->all(),
                $channel->departmentTargets()->pluck('department')->all(),
                $channel->site_wide,
            );

            $existingIds = $channel->memberships()->pluck('user_id')->all();
            $toAdd       = array_diff($newMemberIds, $existingIds);
            $toRemove    = array_diff($existingIds, $newMemberIds);

            if (! empty($toAdd)) {
                $this->chatService->addMembersToChannel($channel, $toAdd);
            }
            if (! empty($toRemove)) {
                $channel->memberships()->whereIn('user_id', $toRemove)->delete();
            }

            AuditLog::record(
                action:       'chat.channel_updated',
                tenantId:     $channel->tenant_id,
                userId:       $request->user()->id,
                resourceType: 'chat_channel',
                resourceId:   $channel->id,
                description:  sprintf('Channel %d retargeted ; +%d / -%d members.', $channel->id, count($toAdd), count($toRemove)),
            );

            broadcast(new ChannelMembersChanged($channel->id, array_values($toAdd), array_values($toRemove)));
        });

        return response()->json(['ok' => true]);
    }

    public function archiveRoleGroup(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->authorizeChannelManage($request->user(), $channel);
        if ($channel->channel_type !== 'role_group') {
            abort(404);
        }
        $channel->update(['is_active' => false]);

        AuditLog::record(
            action:       'chat.channel_archived',
            tenantId:     $channel->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'chat_channel',
            resourceId:   $channel->id,
            description:  sprintf('Channel %d archived.', $channel->id),
        );

        return response()->json(['ok' => true]);
    }

    // ── Group DM management ──────────────────────────────────────────────────

    public function createGroupDm(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'             => ['nullable', 'string', 'max:120'],
            'member_user_ids'  => ['required', 'array', 'min:2'],
            'member_user_ids.*'=> ['integer', 'exists:shared_users,id'],
        ]);

        $channel = $this->chatService->createGroupDmChannel(
            $user->effectiveTenantId(),
            $user,
            $data['name'] ?? null,
            $data['member_user_ids'],
        );

        return response()->json([
            'channel' => [
                'id'           => $channel->id,
                'channel_type' => $channel->channel_type,
                'name'         => $channel->name,
            ],
        ], 201);
    }

    public function renameGroupDm(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        if ($channel->channel_type !== 'group_dm') {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $oldName = $channel->name;
        $channel->update(['name' => $data['name']]);

        AuditLog::record(
            action:       'chat.channel_renamed',
            tenantId:     $channel->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'chat_channel',
            resourceId:   $channel->id,
            description:  sprintf('Renamed group DM from "%s" to "%s".', $oldName ?? '(unnamed)', $data['name']),
        );

        return response()->json(['ok' => true]);
    }

    public function addGroupDmMember(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        if ($channel->channel_type !== 'group_dm') {
            abort(404);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:shared_users,id'],
        ]);

        // Tenant guard.
        $target = User::findOrFail($data['user_id']);
        if ($target->tenant_id !== $channel->tenant_id) {
            abort(403, 'Cannot add a user from another organisation.');
        }

        $this->chatService->addMembersToChannel($channel, [$target->id]);

        AuditLog::record(
            action:       'chat.member_added_by_user',
            tenantId:     $channel->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'chat_channel',
            resourceId:   $channel->id,
            description:  sprintf('User %d added user %d to group DM.', $request->user()->id, $target->id),
        );

        broadcast(new ChannelMembersChanged($channel->id, [$target->id], []));

        return response()->json(['ok' => true]);
    }

    public function removeGroupDmMember(Request $request, ChatChannel $channel, User $user): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        if ($channel->channel_type !== 'group_dm') {
            abort(404);
        }

        $channel->memberships()->where('user_id', $user->id)->delete();

        $isSelf = $user->id === $request->user()->id;
        AuditLog::record(
            action:       $isSelf ? 'chat.member_self_left' : 'chat.member_removed_by_user',
            tenantId:     $channel->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'chat_channel',
            resourceId:   $channel->id,
            description:  $isSelf
                ? sprintf('User %d left group DM.', $user->id)
                : sprintf('User %d removed user %d from group DM.', $request->user()->id, $user->id),
        );

        broadcast(new ChannelMembersChanged($channel->id, [], [$user->id]));

        return response()->json(['ok' => true]);
    }

    // ── Reactions ────────────────────────────────────────────────────────────

    public function addReaction(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $data = $request->validate(['reaction' => ['required', 'string', 'max:16']]);

        $added = $this->chatService->toggleReaction($message, $request->user(), $data['reaction']);

        broadcast(new MessageReacted(
            $channel->id,
            $message->id,
            $request->user()->id,
            $data['reaction'],
            $added ? 'added' : 'removed',
        ));

        return response()->json(['action' => $added ? 'added' : 'removed']);
    }

    public function removeReaction(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        // Same path as addReaction (toggleReaction handles both directions),
        // but kept as a separate route for REST clarity.
        return $this->addReaction($request, $channel, $message);
    }

    // ── Read receipts ────────────────────────────────────────────────────────

    public function markMessageRead(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $existing = $message->reads()->where('user_id', $request->user()->id)->exists();
        $this->chatService->markRead($message, $request->user());

        if (! $existing) {
            broadcast(new MessageRead(
                $channel->id,
                $message->id,
                $request->user()->id,
                now()->toIso8601String(),
            ));
        }

        return response()->json(['ok' => true]);
    }

    public function messageDetails(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $message->load(['reads.user', 'reactions.user', 'channel.memberships.user']);

        return response()->json([
            'sent_at'      => $message->sent_at?->toIso8601String(),
            'reads'        => $message->reads->map(fn ($r) => [
                'user_id' => $r->user_id,
                'name'    => $r->user?->fullName(),
                'read_at' => $r->read_at?->toIso8601String(),
            ])->values(),
            'reactions'    => $message->reactions->map(fn ($r) => [
                'user_id'    => $r->user_id,
                'name'       => $r->user?->fullName(),
                'reaction'   => $r->reaction,
                'reacted_at' => $r->reacted_at?->toIso8601String(),
            ])->values(),
            'total_members' => $channel->memberships()->count(),
        ]);
    }

    // ── Edit / delete ────────────────────────────────────────────────────────

    public function editMessage(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $data = $request->validate([
            'message_text' => ['required', 'string', 'max:4000'],
        ]);

        $message = $this->chatService->editMessage($message, $request->user(), $data['message_text']);

        broadcast(new MessageEdited($message));

        return response()->json(['message' => $message->toApiArray($request->user())]);
    }

    public function deleteMessage(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $this->chatService->deleteMessage($message, $request->user());

        broadcast(new MessageDeleted(
            $channel->id,
            $message->id,
            $request->user()->id,
            now()->toIso8601String(),
        ));

        return response()->json(['ok' => true]);
    }

    // ── Pins ─────────────────────────────────────────────────────────────────

    public function pinMessage(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $override = (bool) $request->boolean('override');
        $pin = $this->chatService->pinMessage($message, $request->user(), $override);

        broadcast(new MessagePinned(
            $channel->id,
            $message->id,
            $request->user()->id,
            $pin->pinned_at->toIso8601String(),
        ));

        return response()->json(['pinned_at' => $pin->pinned_at->toIso8601String()]);
    }

    public function unpinMessage(Request $request, ChatChannel $channel, ChatMessage $message): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->assertMessageBelongsToChannel($channel, $message);

        $this->chatService->unpinMessage($message, $request->user());

        broadcast(new MessageUnpinned($channel->id, $message->id));

        return response()->json(['ok' => true]);
    }

    public function listPins(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $pins = $channel->pins()
            ->with(['message.sender', 'pinnedBy'])
            ->orderBy('pinned_at', 'desc')
            ->get()
            ->map(function (ChatMessagePin $p) {
                return [
                    'message_id'    => $p->message_id,
                    'message_text'  => $p->message?->isDeleted() ? null : $p->message?->message_text,
                    'sender_name'   => $p->message?->sender?->fullName(),
                    'sent_at'       => $p->message?->sent_at?->toIso8601String(),
                    'pinned_by'     => $p->pinnedBy?->fullName(),
                    'pinned_at'     => $p->pinned_at->toIso8601String(),
                ];
            });

        return response()->json([
            'pins'        => $pins,
            'soft_cap'    => ChatMessagePin::SOFT_CAP,
            'at_cap'      => $pins->count() >= ChatMessagePin::SOFT_CAP,
        ]);
    }

    // ── Mute / snooze ────────────────────────────────────────────────────────

    public function mute(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $data = $request->validate([
            'snoozed_until' => ['nullable', 'date'],
        ]);

        $until = isset($data['snoozed_until'])
            ? Carbon::parse($data['snoozed_until'])
            : null;

        $this->chatService->muteChannel($channel, $request->user(), $until);

        return response()->json(['ok' => true]);
    }

    public function unmute(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);
        $this->chatService->unmuteChannel($channel, $request->user());
        return response()->json(['ok' => true]);
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function searchMessages(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $q     = trim((string) $request->query('q', ''));
        $limit = min(50, max(10, (int) $request->query('limit', 30)));

        if (strlen($q) < 2) {
            return response()->json(['matches' => []]);
        }

        $matches = $channel->messages()
            ->withoutTrashed()
            ->whereRaw('LOWER(message_text) LIKE ?', ['%' . strtolower($q) . '%'])
            ->with('sender')
            ->orderBy('sent_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (ChatMessage $m) => [
                'id'           => $m->id,
                'sender_name'  => $m->sender?->fullName(),
                'message_text' => $m->message_text,
                'sent_at'      => $m->sent_at?->toIso8601String(),
            ]);

        return response()->json(['matches' => $matches]);
    }

    // ── Lookup endpoints for the @mention typeahead + role-group creator ───

    /**
     * GET /chat/channels/{channel}/members : all current members of the
     * channel, used by the @mention typeahead in the composer.
     */
    public function listMembers(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $members = $channel->memberships()
            ->with('user:id,first_name,last_name,department,job_title')
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->user_id,
                'name'       => $m->user?->fullName(),
                // mention-friendly handle : Slack-style "first.last" lowercased
                'handle'     => $m->user
                    ? strtolower($m->user->first_name . '.' . $m->user->last_name)
                    : null,
                'department' => $m->user?->department,
                'job_title'  => $m->user?->job_title,
            ])
            ->filter(fn ($m) => $m['handle'] !== null)
            ->values();

        return response()->json(['members' => $members]);
    }

    /**
     * GET /chat/job-titles : all active JobTitles in the caller's tenant.
     * Used by the specialized-channel creation flow (checklist UI) and by
     * the @role part of the mention typeahead.
     */
    public function listJobTitles(Request $request): JsonResponse
    {
        /** @var \App\Models\User $u */
        $u = $request->user();
        $tenantId = $u->effectiveTenantId();

        $titles = \App\Models\JobTitle::forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['code', 'label']);

        return response()->json(['job_titles' => $titles]);
    }

    /**
     * GET /chat/departments : the 14 canonical departments + executive.
     * Static list, but exposed via API for consistency + so the frontend
     * doesn't have to hardcode it.
     */
    public function listDepartments(Request $_request): JsonResponse
    {
        return response()->json(['departments' => [
            ['slug' => 'primary_care',      'label' => 'Primary Care / Nursing'],
            ['slug' => 'therapies',         'label' => 'Therapies'],
            ['slug' => 'social_work',       'label' => 'Social Work'],
            ['slug' => 'behavioral_health', 'label' => 'Behavioral Health'],
            ['slug' => 'dietary',           'label' => 'Dietary'],
            ['slug' => 'activities',        'label' => 'Activities'],
            ['slug' => 'home_care',         'label' => 'Home Care'],
            ['slug' => 'transportation',    'label' => 'Transportation'],
            ['slug' => 'pharmacy',          'label' => 'Pharmacy'],
            ['slug' => 'idt',               'label' => 'IDT'],
            ['slug' => 'enrollment',        'label' => 'Enrollment'],
            ['slug' => 'finance',           'label' => 'Finance'],
            ['slug' => 'qa_compliance',     'label' => 'QA / Compliance'],
            ['slug' => 'it_admin',          'label' => 'IT Admin'],
            ['slug' => 'executive',         'label' => 'Executive' ],
        ]]);
    }

    // ── Channel Settings (with audit timeline) ──────────────────────────────

    public function settings(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $channel->load(['memberships.user', 'roleTargets', 'departmentTargets', 'mutes' => fn ($q) => $q->where('user_id', $request->user()->id)]);

        $audit = AuditLog::where('resource_type', 'chat_channel')
            ->where('resource_id', $channel->id)
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get(['id', 'action', 'user_id', 'description', 'created_at'])
            ->map(fn ($row) => [
                'action'      => $row->action,
                'description' => $row->description,
                'actor_id'    => $row->user_id,
                'at'          => optional($row->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'channel' => [
                'id'           => $channel->id,
                'channel_type' => $channel->channel_type,
                'name'         => $channel->name,
                'description'  => $channel->description,
                'site_wide'    => $channel->site_wide,
                'targets'      => $channel->channel_type === 'role_group' ? [
                    'roles'       => $channel->roleTargets->pluck('job_title_code')->all(),
                    'departments' => $channel->departmentTargets->pluck('department')->all(),
                    'site_wide'   => $channel->site_wide,
                ] : null,
                'members'      => $channel->memberships->map(fn ($m) => [
                    'user_id'   => $m->user_id,
                    'name'      => $m->user?->fullName(),
                    'department' => $m->user?->department,
                    'job_title' => $m->user?->job_title,
                    'joined_at' => $m->joined_at?->toIso8601String(),
                ])->values(),
            ],
            'audit_timeline' => $audit,
        ]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Abort 403 if the given user is not a member of the channel.
     */
    private function requireMembership(User $user, ChatChannel $channel): void
    {
        $isMember = $channel->memberships()
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this channel.');
        }
    }

    /**
     * Asserts the message belongs to the channel ; defends against scoped-binding
     * gaps where a route otherwise resolves any ChatMessage by primary key.
     */
    private function assertMessageBelongsToChannel(ChatChannel $channel, ChatMessage $message): void
    {
        if ($message->channel_id !== $channel->id) {
            abort(404);
        }
    }

    /**
     * Authorize "manage" (rename / retarget / archive) on a channel. Delegates
     * to ChatChannel::canManage() which encodes the §11.2 matrix.
     */
    private function authorizeChannelManage(User $actor, ChatChannel $channel): void
    {
        if (! $channel->canManage($actor)) {
            abort(403, 'You do not have permission to manage this channel.');
        }
    }

    /**
     * Permission check at role-group create time. Mirrors §11.2 :
     *   - Site-wide : exec / it_admin admin / super_admin only.
     *   - Per-dept  : admin of any target dept, OR exec, OR it_admin admin,
     *                 OR super_admin.
     */
    private function requireRoleGroupCreatePermission(User $actor, array $departments, bool $siteWide): void
    {
        if ($actor->isSuperAdmin() || $actor->isDeptSuperAdmin()) {
            return;
        }

        $isExec        = $actor->department === 'executive';
        $isItAdmin     = $actor->role === 'admin' && $actor->department === 'it_admin';

        if ($siteWide) {
            if (! $isExec && ! $isItAdmin) {
                abort(403, 'Site-wide specialized chats require executive or IT admin authorization.');
            }
            return;
        }

        if ($isExec || $isItAdmin) {
            return;
        }

        if ($actor->role === 'admin' && in_array($actor->department, $departments, true)) {
            return;
        }

        abort(403, 'Only department admins can create specialized chats for their department.');
    }
}
