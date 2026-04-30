<?php

// ─── ChatService ──────────────────────────────────────────────────────────────
// Handles channel + message lifecycle for Chat v2.
//
// Channel creation :
//   createDepartmentChannels() : 14 dept + 1 broadcast on tenant setup
//   createParticipantIdtChannel() : per participant on enrollment
//   createRoleGroupChannel() : "specialized" group chat by JobTitle + dept
//   createGroupDmChannel() : user-created multi-member group DM
//   getOrCreateDmChannel() : 1:1 DM lookup-or-create
//
// Auto-add hook :
//   syncRoleGroupMemberships(User) : called by UserJobTitleObserver when
//   job_title or department changes. Adds the user to every role_group
//   channel they now qualify for ; removes them from any they no longer do.
//   Audit-logged on both sides.
//
// Message-level operations :
//   pinMessage / unpinMessage   (50-cap with admin override)
//   toggleReaction              (insert / delete in one call)
//   muteChannel / unmuteChannel
//   parseAndStoreMentions       (called by send())
//   editMessage                 (5-min window, preserves original_message_text)
//   deleteMessage               (soft-delete + audit)
//   markRead                    (idempotent first-read receipt + audit)
//
// All actions that touch PHI write to shared_audit_logs. See
// docs/plans/chat_v2_plan.md §5 + §8.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChatChannel;
use App\Models\ChatChannelMute;
use App\Models\ChatMembership;
use App\Models\ChatMessage;
use App\Models\ChatMessageMention;
use App\Models\ChatMessagePin;
use App\Models\ChatMessageReaction;
use App\Models\ChatMessageRead;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChatService
{
    /** All 14 department slugs. */
    private const DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'activities', 'home_care', 'transportation', 'pharmacy',
        'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
    ];

    /**
     * IDT participant channel members : these departments join every
     * participant_idt channel automatically on enrollment.
     */
    private const IDT_CHANNEL_DEPARTMENTS = [
        'idt', 'primary_care', 'social_work', 'therapies', 'pharmacy', 'behavioral_health',
    ];

    // ── Department + broadcast channel bootstrap ─────────────────────────────

    /**
     * Create the standard set of channels for a new tenant :
     *  - 14 department channels (one per dept, all users in that dept added)
     *  - 1 broadcast channel (all users in the tenant added)
     *
     * Idempotent : skips channel types / departments that already exist.
     */
    public function createDepartmentChannels(int $tenantId, User $createdBy): void
    {
        $broadcast = ChatChannel::firstOrCreate(
            ['tenant_id' => $tenantId, 'channel_type' => 'broadcast'],
            [
                'name'               => 'All Staff',
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]
        );

        $allUserIds = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        $this->addMembersToChannel($broadcast, $allUserIds);

        foreach (self::DEPARTMENTS as $dept) {
            $deptLabel = ucwords(str_replace('_', ' ', $dept));

            $channel = ChatChannel::firstOrCreate(
                [
                    'tenant_id'    => $tenantId,
                    'channel_type' => 'department',
                    'name'         => $deptLabel,
                ],
                [
                    'created_by_user_id' => $createdBy->id,
                    'is_active'          => true,
                ]
            );

            $deptUserIds = $this->getDepartmentUserIds($tenantId, $dept);
            $this->addMembersToChannel($channel, $deptUserIds);
        }
    }

    /**
     * Create a participant_idt channel for a newly enrolled participant.
     * Members : all active users from IDT_CHANNEL_DEPARTMENTS in the tenant.
     */
    public function createParticipantIdtChannel(Participant $participant, User $createdBy): ChatChannel
    {
        $channelName = trim($participant->first_name . ' ' . $participant->last_name) . ' : IDT';

        $channel = ChatChannel::firstOrCreate(
            [
                'tenant_id'      => $participant->tenant_id,
                'channel_type'   => 'participant_idt',
                'participant_id' => $participant->id,
            ],
            [
                'name'               => $channelName,
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]
        );

        $memberIds = User::where('tenant_id', $participant->tenant_id)
            ->where('is_active', true)
            ->whereIn('department', self::IDT_CHANNEL_DEPARTMENTS)
            ->pluck('id')
            ->toArray();

        $this->addMembersToChannel($channel, $memberIds);

        return $channel;
    }

    // ── Chat v2 : role-group + group-DM channel creation ─────────────────────

    /**
     * Create a role-group ("specialized") channel. Targets one or more
     * JobTitles, scoped to one or more departments OR site-wide. After
     * creation, every active user matching the targets is auto-joined
     * (full backfill of history visible per §11.5).
     *
     * Caller must already have permission ; controller enforces that.
     *
     * @param  string[]  $jobTitleCodes  e.g. ['rn', 'lpn']
     * @param  string[]  $departments    empty when $siteWide is true
     */
    public function createRoleGroupChannel(
        int $tenantId,
        User $createdBy,
        string $name,
        ?string $description,
        array $jobTitleCodes,
        array $departments,
        bool $siteWide,
    ): ChatChannel {
        if (empty($jobTitleCodes)) {
            throw ValidationException::withMessages([
                'job_title_codes' => 'At least one JobTitle is required.',
            ]);
        }
        if (! $siteWide && empty($departments)) {
            throw ValidationException::withMessages([
                'departments' => 'Either select departments or mark the channel as site-wide.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $createdBy, $name, $description, $jobTitleCodes, $departments, $siteWide) {
            $channel = ChatChannel::create([
                'tenant_id'          => $tenantId,
                'channel_type'       => 'role_group',
                'name'               => $name,
                'description'        => $description,
                'site_wide'          => $siteWide,
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]);

            // Targets pivots.
            foreach ($jobTitleCodes as $code) {
                $channel->roleTargets()->create(['job_title_code' => $code]);
            }
            if (! $siteWide) {
                foreach ($departments as $dept) {
                    $channel->departmentTargets()->create(['department' => $dept]);
                }
            }

            // Initial membership : every user matching the targets.
            $matchingUserIds = $this->resolveRoleGroupMembers($tenantId, $jobTitleCodes, $departments, $siteWide);
            $this->addMembersToChannel($channel, $matchingUserIds);

            AuditLog::record(
                action:       'chat.channel_created',
                tenantId:     $tenantId,
                userId:       $createdBy->id,
                resourceType: 'chat_channel',
                resourceId:   $channel->id,
                description:  sprintf(
                    'Specialized chat created : %s (titles : %s, departments : %s)',
                    $name,
                    implode(',', $jobTitleCodes),
                    $siteWide ? 'site-wide' : implode(',', $departments),
                ),
            );

            return $channel;
        });
    }

    /**
     * Create a user-driven group DM with N members. No JobTitle / dept
     * targeting ; members are picked by name. Anyone in the tenant can
     * create one ; it lives in the "Direct Messages" group in the UI.
     *
     * @param  int[]  $memberUserIds  must be ≥ 2 (creator + at least one other)
     */
    public function createGroupDmChannel(
        int $tenantId,
        User $createdBy,
        ?string $name,
        array $memberUserIds,
    ): ChatChannel {
        $memberUserIds = array_unique(array_filter($memberUserIds));

        // Always include the creator.
        if (! in_array($createdBy->id, $memberUserIds, true)) {
            $memberUserIds[] = $createdBy->id;
        }

        if (count($memberUserIds) < 3) {
            throw ValidationException::withMessages([
                'member_user_ids' => 'A group DM needs at least 3 members. Use a 1:1 DM for two people.',
            ]);
        }

        // All members must be in the same tenant (no cross-tenant DMs).
        $validMemberCount = User::whereIn('id', $memberUserIds)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();
        if ($validMemberCount !== count($memberUserIds)) {
            throw ValidationException::withMessages([
                'member_user_ids' => 'All members must be active users in your organisation.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $createdBy, $name, $memberUserIds) {
            $channel = ChatChannel::create([
                'tenant_id'          => $tenantId,
                'channel_type'       => 'group_dm',
                'name'               => $name,
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]);

            $this->addMembersToChannel($channel, $memberUserIds);

            AuditLog::record(
                action:       'chat.channel_created',
                tenantId:     $tenantId,
                userId:       $createdBy->id,
                resourceType: 'chat_channel',
                resourceId:   $channel->id,
                description:  sprintf('Group DM created : %s (%d members)', $name ?? '(unnamed)', count($memberUserIds)),
            );

            return $channel;
        });
    }

    /**
     * Find or create a 1:1 direct-message channel between two users.
     * (Existing method, unchanged from v1.)
     */
    public function getOrCreateDmChannel(User $userA, User $userB, int $tenantId): ChatChannel
    {
        $existing = ChatChannel::where('tenant_id', $tenantId)
            ->where('channel_type', 'direct')
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $userA->id))
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $userB->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        $channel = ChatChannel::create([
            'tenant_id'          => $tenantId,
            'channel_type'       => 'direct',
            'name'               => null,
            'created_by_user_id' => $userA->id,
            'is_active'          => true,
        ]);

        $this->addMembersToChannel($channel, [$userA->id, $userB->id]);

        return $channel;
    }

    // ── Auto-add hook ────────────────────────────────────────────────────────

    /**
     * Sync a single user's role-group memberships against the channels they
     * now qualify for (based on their current job_title + department).
     *
     * Called by UserJobTitleObserver on User::updated. Idempotent : adds
     * memberships that don't exist, removes ones that no longer qualify,
     * leaves the rest alone.
     */
    public function syncRoleGroupMemberships(User $user): void
    {
        if (! $user->is_active) {
            // Inactive users are not auto-added anywhere. Active retirement
            // (e.g. 6-month read-only window) flows through different code.
            return;
        }

        $tenantId      = $user->tenant_id;
        $jobTitleCode  = $user->job_title;
        $department    = $user->department;

        // Channels the user CURRENTLY qualifies for.
        $shouldBelongTo = collect();
        if ($jobTitleCode) {
            $shouldBelongTo = ChatChannel::query()
                ->forJobTitleAndDept($jobTitleCode, $department)
                ->where('tenant_id', $tenantId)
                ->pluck('id');
        }

        // Channels the user is CURRENTLY a member of (role_group only).
        $alreadyIn = ChatMembership::query()
            ->where('user_id', $user->id)
            ->whereIn(
                'channel_id',
                ChatChannel::roleGroup()
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')
            )
            ->pluck('channel_id');

        $toAdd    = $shouldBelongTo->diff($alreadyIn);
        $toRemove = $alreadyIn->diff($shouldBelongTo);

        foreach ($toAdd as $channelId) {
            $this->addMembersToChannel(ChatChannel::find($channelId), [$user->id]);
            AuditLog::record(
                action:       'chat.member_added_by_role',
                tenantId:     $tenantId,
                userId:       $user->id,
                resourceType: 'chat_channel',
                resourceId:   $channelId,
                description:  sprintf('User %d auto-added to role-group channel %d (job_title=%s, dept=%s)',
                    $user->id, $channelId, $jobTitleCode, $department),
            );
        }

        if ($toRemove->isNotEmpty()) {
            ChatMembership::where('user_id', $user->id)
                ->whereIn('channel_id', $toRemove)
                ->delete();
            foreach ($toRemove as $channelId) {
                AuditLog::record(
                    action:       'chat.member_removed_by_role',
                    tenantId:     $tenantId,
                    userId:       $user->id,
                    resourceType: 'chat_channel',
                    resourceId:   $channelId,
                    description:  sprintf('User %d auto-removed from role-group channel %d (now job_title=%s, dept=%s)',
                        $user->id, $channelId, $jobTitleCode, $department),
                );
            }
        }
    }

    /**
     * Resolve the set of users that should be members of a role-group
     * channel given its targets. Used at create time (initial seeding) and
     * at retarget time (re-sync).
     *
     * @return int[]
     */
    public function resolveRoleGroupMembers(int $tenantId, array $jobTitleCodes, array $departments, bool $siteWide): array
    {
        $query = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('job_title', $jobTitleCodes);

        if (! $siteWide) {
            $query->whereIn('department', $departments);
        }

        return $query->pluck('id')->toArray();
    }

    // ── Pin / unpin ──────────────────────────────────────────────────────────

    /**
     * Pin a message. Enforces the 50-pin soft cap unless $override is true,
     * in which case caller must already have channel admin rights AND we
     * write a chat.pin_cap_override audit row.
     */
    public function pinMessage(ChatMessage $message, User $actor, bool $override = false): ChatMessagePin
    {
        $channel = $message->channel;
        if (! $channel) {
            throw new RuntimeException('Message has no channel.');
        }

        if (! $channel->canPin($actor)) {
            throw new RuntimeException('You do not have permission to pin in this channel.');
        }

        $currentPinCount = $channel->pins()->count();

        if ($currentPinCount >= ChatMessagePin::SOFT_CAP) {
            if (! $override) {
                throw ValidationException::withMessages([
                    'pin' => sprintf(
                        'This channel has reached its %d-pin limit. Unpin something first, or override.',
                        ChatMessagePin::SOFT_CAP
                    ),
                ]);
            }

            // Override path : audit-logged in addition to the standard pin row.
            AuditLog::record(
                action:       'chat.pin_cap_override',
                tenantId:     $channel->tenant_id,
                userId:       $actor->id,
                resourceType: 'chat_channel',
                resourceId:   $channel->id,
                description:  sprintf('Admin %d pinned message %d past the %d-pin cap.', $actor->id, $message->id, ChatMessagePin::SOFT_CAP),
            );
        }

        $pin = ChatMessagePin::firstOrCreate(
            ['message_id' => $message->id],
            [
                'channel_id'        => $channel->id,
                'pinned_by_user_id' => $actor->id,
                'pinned_at'         => now(),
            ],
        );

        AuditLog::record(
            action:       'chat.message_pinned',
            tenantId:     $channel->tenant_id,
            userId:       $actor->id,
            resourceType: 'chat_message',
            resourceId:   $message->id,
            description:  sprintf('Message %d pinned in channel %d.', $message->id, $channel->id),
        );

        return $pin;
    }

    public function unpinMessage(ChatMessage $message, User $actor): void
    {
        $channel = $message->channel;
        if (! $channel) {
            throw new RuntimeException('Message has no channel.');
        }

        if (! $channel->canPin($actor)) {
            throw new RuntimeException('You do not have permission to unpin in this channel.');
        }

        ChatMessagePin::where('message_id', $message->id)->delete();

        AuditLog::record(
            action:       'chat.message_unpinned',
            tenantId:     $channel->tenant_id,
            userId:       $actor->id,
            resourceType: 'chat_message',
            resourceId:   $message->id,
            description:  sprintf('Message %d unpinned in channel %d.', $message->id, $channel->id),
        );
    }

    // ── Reactions ────────────────────────────────────────────────────────────

    /**
     * Toggle a reaction on a message for a user. Returns true if the
     * reaction was added, false if it was removed.
     */
    public function toggleReaction(ChatMessage $message, User $actor, string $reaction): bool
    {
        if (! in_array($reaction, ChatMessageReaction::REACTION_CODES, true)) {
            throw ValidationException::withMessages([
                'reaction' => 'Unknown reaction.',
            ]);
        }

        $existing = ChatMessageReaction::where('message_id', $message->id)
            ->where('user_id', $actor->id)
            ->where('reaction', $reaction)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        ChatMessageReaction::create([
            'message_id' => $message->id,
            'user_id'    => $actor->id,
            'reaction'   => $reaction,
            'reacted_at' => now(),
        ]);
        return true;
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Mark a message as read by $reader. Idempotent : second call is a no-op.
     * On first read, writes the audit row (PHI access trail).
     *
     * Returns true if this was the first read (audit was written), false if
     * the row already existed. Uses firstOrCreate() so two concurrent
     * IntersectionObserver hits don't race the unique constraint :
     * the DB resolves the race atomically and one of the two callers gets
     * "already existed" without an exception.
     */
    public function markRead(ChatMessage $message, User $reader): bool
    {
        $row = ChatMessageRead::firstOrCreate(
            ['message_id' => $message->id, 'user_id' => $reader->id],
            ['read_at' => now()],
        );

        // wasRecentlyCreated is true only when this call won the race.
        if (! $row->wasRecentlyCreated) {
            return false;
        }

        AuditLog::record(
            action:       'chat.message_read',
            tenantId:     $message->channel?->tenant_id,
            userId:       $reader->id,
            resourceType: 'chat_message',
            resourceId:   $message->id,
            description:  sprintf('User %d first-read message %d.', $reader->id, $message->id),
        );

        return true;
    }

    // ── Mute / snooze ────────────────────────────────────────────────────────

    public function muteChannel(ChatChannel $channel, User $user, ?Carbon $until = null): ChatChannelMute
    {
        $row = ChatChannelMute::updateOrCreate(
            ['channel_id' => $channel->id, 'user_id' => $user->id],
            ['muted_at' => now(), 'snoozed_until' => $until],
        );
        return $row;
    }

    public function unmuteChannel(ChatChannel $channel, User $user): void
    {
        ChatChannelMute::where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Is this user actively muting this channel right now ? @mention
     * detection happens upstream — this only checks raw mute state.
     */
    public function isMuted(ChatChannel $channel, User $user): bool
    {
        return ChatChannelMute::active()
            ->where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    // ── Mentions parsing ─────────────────────────────────────────────────────

    /**
     * Scan message_text for @user / @role / @dept / @all patterns and
     * insert ChatMessageMention rows. Called by send() after the message
     * row is persisted.
     *
     * Patterns recognised :
     *   @user.name      → resolve against User.first_name + last_name in tenant
     *   @ROLE-CODE      → resolve against JobTitle.code in tenant
     *   @dept-name      → resolve against department slug
     *   @all  | @channel → is_at_all = true
     *
     * Lookups are case-insensitive ; usernames replace spaces with dots
     * (Slack convention) so "@john.smith" resolves to "John Smith".
     */
    public function parseAndStoreMentions(ChatMessage $message): void
    {
        $text = $message->message_text;
        if (! $text || ! preg_match_all('/@([A-Za-z0-9_\.\-]+)/', $text, $matches)) {
            return;
        }

        $tenantId = $message->channel?->tenant_id;
        if (! $tenantId) {
            return;
        }

        $tokens = array_unique(array_map('strtolower', $matches[1]));

        foreach ($tokens as $token) {
            // @all / @channel : tenant-wide channel mention.
            if (in_array($token, ['all', 'channel'], true)) {
                ChatMessageMention::create([
                    'message_id' => $message->id,
                    'is_at_all'  => true,
                ]);
                continue;
            }

            // @dept-name : compare with hyphens converted to underscores
            // (Slack-style channel slugs use hyphens ; our DB uses underscores).
            $deptSlug = str_replace('-', '_', $token);
            $isDept = in_array($deptSlug, [
                'primary_care', 'therapies', 'social_work', 'behavioral_health',
                'dietary', 'activities', 'home_care', 'transportation',
                'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance',
                'it_admin', 'executive',
            ], true);
            if ($isDept) {
                ChatMessageMention::create([
                    'message_id'           => $message->id,
                    'mentioned_department' => $deptSlug,
                ]);
                continue;
            }

            // @role : compare against JobTitle.code in this tenant.
            $jobTitleExists = DB::table('emr_job_titles')
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(code) = ?', [$token])
                ->whereNull('deleted_at')
                ->exists();
            if ($jobTitleExists) {
                ChatMessageMention::create([
                    'message_id'          => $message->id,
                    'mentioned_role_code' => $token,
                ]);
                continue;
            }

            // @user.name : "john.smith" → User WHERE first='john' AND last='smith'.
            $parts = explode('.', $token, 2);
            if (count($parts) === 2) {
                [$first, $last] = $parts;
                $user = User::where('tenant_id', $tenantId)
                    ->whereRaw('LOWER(first_name) = ?', [$first])
                    ->whereRaw('LOWER(last_name) = ?', [$last])
                    ->where('is_active', true)
                    ->first();
                if ($user) {
                    ChatMessageMention::create([
                        'message_id'        => $message->id,
                        'mentioned_user_id' => $user->id,
                    ]);
                }
            }
        }
    }

    // ── Edit / delete with audit ─────────────────────────────────────────────

    /**
     * Edit a message. Allowed only by the sender, only within the 5-min
     * edit window. Preserves the FIRST sent text in original_message_text
     * on first edit (subsequent edits don't overwrite that column).
     */
    public function editMessage(ChatMessage $message, User $actor, string $newText): ChatMessage
    {
        if ($message->sender_user_id !== $actor->id) {
            throw new RuntimeException('Only the message sender can edit a message.');
        }
        if (! $message->isWithinEditWindow()) {
            throw ValidationException::withMessages([
                'message_text' => sprintf('Edit window of %d minutes has passed.', ChatMessage::EDIT_WINDOW_MINUTES),
            ]);
        }

        return DB::transaction(function () use ($message, $actor, $newText) {
            if ($message->original_message_text === null) {
                $message->original_message_text = $message->message_text;
            }
            $message->message_text = $newText;
            $message->edited_at    = now();
            $message->save();

            AuditLog::record(
                action:       'chat.message_edited',
                tenantId:     $message->channel?->tenant_id,
                userId:       $actor->id,
                resourceType: 'chat_message',
                resourceId:   $message->id,
                description:  sprintf('Sender edited message %d within the 5-minute window.', $message->id),
            );

            return $message;
        });
    }

    /**
     * Soft-delete a message. Allowed by sender, channel admin, or super-admin.
     * Records who deleted in deleted_by_user_id alongside the standard
     * deleted_at timestamp.
     */
    public function deleteMessage(ChatMessage $message, User $actor): ChatMessage
    {
        $isSender = $message->sender_user_id === $actor->id;
        $isAdmin  = $message->channel && $message->channel->canManage($actor);
        $isSuper  = $actor->isSuperAdmin() || $actor->isDeptSuperAdmin();
        if (! $isSender && ! $isAdmin && ! $isSuper) {
            throw new RuntimeException('You do not have permission to delete this message.');
        }

        return DB::transaction(function () use ($message, $actor) {
            $message->deleted_by_user_id = $actor->id;
            $message->save();
            $message->delete(); // SoftDeletes trait sets deleted_at

            AuditLog::record(
                action:       'chat.message_deleted',
                tenantId:     $message->channel?->tenant_id,
                userId:       $actor->id,
                resourceType: 'chat_message',
                resourceId:   $message->id,
                description:  sprintf('Message %d soft-deleted by user %d.', $message->id, $actor->id),
            );

            // Auto-clear pin if the deleted message was pinned.
            ChatMessagePin::where('message_id', $message->id)->delete();

            return $message;
        });
    }

    // ── Existing helpers (unchanged) ─────────────────────────────────────────

    /** Bulk-insert memberships, ignoring duplicates (idempotent). */
    public function addMembersToChannel(ChatChannel $channel, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $now  = Carbon::now();
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'channel_id' => $channel->id,
                'user_id'    => $userId,
                'joined_at'  => $now,
            ];
        }
        DB::table('emr_chat_memberships')->insertOrIgnore($rows);
    }

    /** Return IDs of all active users in a given department for a tenant. */
    public function getDepartmentUserIds(int $tenantId, string $department): array
    {
        return User::where('tenant_id', $tenantId)
            ->where('department', $department)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }
}
