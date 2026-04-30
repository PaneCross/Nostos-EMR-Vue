# Chat v2 — implementation plan

**Status:** drafted 2026-04-30, awaiting TJ sign-off before any code lands.
**Scope:** end-to-end overhaul of the existing Phase 7C chat feature into a
clinical-grade group-chat surface. Backwards-compatible with the existing
`emr_chat_channels` / `emr_chat_messages` / `emr_chat_memberships` triple.
**Trigger:** TJ's 2026-04-30 spec, validated decisions captured in the
"Locked-in decisions" section below.

---

## 1. Locked-in decisions (TJ confirmed)

| Decision | Value |
|---|---|
| What "role" means | `JobTitle` (RN, MD, PT, ...). Tenant-defined catalog at `emr_job_titles`. |
| Channel-type sort order | Specialized → Department → Broadcast → Direct (1:1 + group DMs) |
| Categories collapsible | Yes, with badge-when-collapsed showing unread + urgent count |
| Specialized chat scope | Admin picks at creation : multi-select **departments** OR **site-wide** |
| Who creates specialized chats | Dept admin (in target dept), executive, `it_admin` admin (override, audit-logged), or super_admin. Site-wide chats need executive/it_admin/super_admin only. See §11.2. |
| Auto-add by JobTitle | Yes. Adding a user with matching JobTitle auto-joins all relevant chats. |
| Backfill history on auto-add | Full history visible. Creator dialog warns about this at creation time. |
| Edit policy | 5-minute edit window after send, then locked |
| Delete policy | Soft delete only, with audit log + "deleted by [user] at [time]" placeholder |
| Reactions | 5 emoji palette : 👍 ✅ 👀 ❤️ ❓ |
| @mentions | `@user`, `@all`, `@role` (e.g. `@RN`), `@dept` (e.g. `@primary-care`). All four highlight + force notification (override mute). |
| Mute / snooze | Per-channel. Mute = indefinite, Snooze = until timestamp. Overridden by @mention. |
| Audit log message reads | Yes : every first-read writes a `chat.message_read` audit row. |
| In-channel search | Yes for v1 (per-channel only ; cross-channel deferred) |
| Pinned messages | Yes for v1. Permission matrix below. 50-pin soft cap with admin-only `Pin anyway` override (audit-logged). See §11.7. |
| Pin notifications | Loud for specialized / department / broadcast. Silent for DM / group DM. |
| Group DM management | Any member can rename / add / remove / self-leave. All actions audit-logged + visible to members via Channel Settings panel. See §11.6. |
| Real-time delivery | Reverb-first ; graceful polling fallback when Reverb is unavailable (e.g. Render free tier) |
| Attachments | Deferred to v3 |

### Pin permission matrix

| Channel type | Who can pin |
|---|---|
| Specialized (role-group) | Any admin of the target department(s) |
| Department | Any admin of that department |
| Broadcast (site-wide) | `department='executive'` or `role='super_admin'` |
| Direct (1:1) | Either participant |
| Group DM | Any member |

Edge cases :
- Edited pinned message → pin auto-tracks the edit (live link).
- Soft-deleted pinned message → pin auto-clears.
- User changes JobTitle out of role → auto-removed from role-group channel ; their messages stay attributed with "(no longer in this role)" indicator.
- Pinning a message to broadcast → confirmation dialog warns about PHI exposure to all staff.
- Soft cap : 50 pins per channel ; oldest auto-rotates with warning.

### Reaction permissions

- Anyone in the channel can react. No role restriction.
- Reaction notifications are silent (would be too noisy).

---

## 2. Existing state (what we're building on)

**Already exists :**
- `emr_chat_channels` with `channel_type` enum `direct | department | participant_idt | broadcast`
- `emr_chat_memberships` with `last_read_at` for unread counts
- `emr_chat_messages` with soft-delete + `priority` (`standard|urgent`) + `edited_at` column
- `ChatService` for channel lifecycle (department auto-create, IDT auto-create on enrollment, DM lookup)
- `ChatController` REST endpoints (channels, messages, send, markRead, directMessage, unreadCount, searchUsers)
- `NewChatMessage` + `ChatActivityEvent` Reverb broadcasts
- `Chat/Index.vue` two-column UI with grouping by `channel_type`
- 14 dept channels + 1 broadcast auto-created on tenant setup
- Per-participant IDT channels auto-created on enrollment (we keep this)

**Open question we need to resolve before implementation :**
- The existing `participant_idt` channel type is not in TJ's four-group sort spec. **Recommendation :** keep it as a fifth group, slotted between **Broadcast** and **DMs** (it's clinical workflow, like specialized, but bound to a participant). UI shows it as "Participant Care Teams" with the participant name on each channel. Confirm with TJ in the open-questions section below.

---

## 3. Schema changes

All migrations are tenant-scoped. None hard-delete data. Numeric IDs are `bigInt`. New tables follow the `emr_chat_*` prefix convention.

### 3.1 Extend `emr_chat_channels`

Add new channel types and columns :

```php
// Migration: add role_group + group_dm to channel_type enum
// Postgres enum migrations are awkward — easier to switch to varchar(20) + CHECK
Schema::table('emr_chat_channels', function (Blueprint $t) {
    // Drop the old enum, add a varchar with CHECK constraint
    DB::statement("ALTER TABLE emr_chat_channels ALTER COLUMN channel_type TYPE varchar(20)");
    DB::statement("ALTER TABLE emr_chat_channels ADD CONSTRAINT chat_channels_type_check
        CHECK (channel_type IN ('direct', 'department', 'participant_idt', 'broadcast', 'role_group', 'group_dm'))");

    $t->string('description', 500)->nullable()->after('name');
    $t->boolean('site_wide')->default(false)->after('description');
});
```

`description` : optional channel topic/purpose, shown in channel header.
`site_wide` : true for role-group channels that target the entire tenant ;
false (default) when targeted to specific departments.

### 3.2 New table : `emr_chat_channel_role_targets`

Many-to-many : a role-group channel targets one or more JobTitles.

```php
Schema::create('emr_chat_channel_role_targets', function (Blueprint $t) {
    $t->id();
    $t->foreignId('channel_id')->constrained('emr_chat_channels')->cascadeOnDelete();
    $t->string('job_title_code', 60); // matches User.job_title / JobTitle.code
    $t->timestamp('created_at')->useCurrent();

    $t->unique(['channel_id', 'job_title_code']);
    $t->index('job_title_code'); // for the auto-add-on-job-title-change query
});
```

### 3.3 New table : `emr_chat_channel_department_targets`

Many-to-many : a role-group channel targets one or more departments.
Empty for site-wide (`site_wide = true`) channels.

```php
Schema::create('emr_chat_channel_department_targets', function (Blueprint $t) {
    $t->id();
    $t->foreignId('channel_id')->constrained('emr_chat_channels')->cascadeOnDelete();
    $t->string('department', 30); // matches User.department
    $t->timestamp('created_at')->useCurrent();

    $t->unique(['channel_id', 'department']);
    $t->index('department');
});
```

### 3.4 New table : `emr_chat_message_reactions`

```php
Schema::create('emr_chat_message_reactions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('message_id')->constrained('emr_chat_messages')->cascadeOnDelete();
    $t->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();
    $t->string('reaction', 16); // 'thumbs_up', 'check', 'eyes', 'heart', 'question'
    $t->timestamp('reacted_at')->useCurrent();

    // One user can have ONE reaction of a given type per message
    // (they can have multiple distinct types, e.g. 👍 + ❤️)
    $t->unique(['message_id', 'user_id', 'reaction']);
    $t->index(['message_id', 'reaction']); // for count-per-reaction queries
});
```

Whitelisted reaction codes :
- `thumbs_up` (👍)
- `check` (✅)
- `eyes` (👀)
- `heart` (❤️)
- `question` (❓)

Stored as semantic codes, not raw emoji, so we can re-skin the UI without a
DB migration if accessibility / localization demands it later.

### 3.5 New table : `emr_chat_message_reads`

Per-message read receipts. Distinct from `chat_memberships.last_read_at`
which only tracks the channel-level high-water mark.

```php
Schema::create('emr_chat_message_reads', function (Blueprint $t) {
    $t->id();
    $t->foreignId('message_id')->constrained('emr_chat_messages')->cascadeOnDelete();
    $t->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();
    $t->timestamp('read_at')->useCurrent();

    $t->unique(['message_id', 'user_id']); // first read only
    $t->index('user_id');
});
```

A row is created the first time a user's chat client reports the message
as visible (Intersection Observer in the Vue layer). Idempotent : second
visibility events for the same (message, user) pair are no-ops. Each first
read also writes a `chat.message_read` audit log row.

### 3.6 New table : `emr_chat_message_pins`

```php
Schema::create('emr_chat_message_pins', function (Blueprint $t) {
    $t->id();
    $t->foreignId('channel_id')->constrained('emr_chat_channels')->cascadeOnDelete();
    $t->foreignId('message_id')->constrained('emr_chat_messages')->cascadeOnDelete();
    $t->foreignId('pinned_by_user_id')->constrained('shared_users')->cascadeOnDelete();
    $t->timestamp('pinned_at')->useCurrent();

    $t->unique('message_id'); // a message is either pinned once or not pinned
    $t->index(['channel_id', 'pinned_at']);
});
```

### 3.7 New table : `emr_chat_channel_mutes`

```php
Schema::create('emr_chat_channel_mutes', function (Blueprint $t) {
    $t->id();
    $t->foreignId('channel_id')->constrained('emr_chat_channels')->cascadeOnDelete();
    $t->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();
    $t->timestamp('muted_at')->useCurrent();
    $t->timestamp('snoozed_until')->nullable(); // null = indefinite mute

    $t->unique(['channel_id', 'user_id']);
    $t->index(['user_id', 'snoozed_until']);
});
```

Resolved at notification dispatch time : if a row exists and `snoozed_until`
is null OR in the future, suppress the notification — UNLESS the message
contains an `@mention` of the user (which always overrides).

### 3.8 New table : `emr_chat_message_mentions`

```php
Schema::create('emr_chat_message_mentions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('message_id')->constrained('emr_chat_messages')->cascadeOnDelete();

    // Exactly one of these is non-null per row.
    $t->foreignId('mentioned_user_id')->nullable()->constrained('shared_users')->cascadeOnDelete();
    $t->string('mentioned_role_code', 60)->nullable();      // e.g. 'rn' (JobTitle code)
    $t->string('mentioned_department', 30)->nullable();     // e.g. 'primary_care'
    $t->boolean('is_at_all')->default(false);               // @all / @channel

    $t->timestamp('created_at')->useCurrent();

    $t->index(['mentioned_user_id', 'message_id']);
    $t->index(['mentioned_role_code']);
    $t->index(['mentioned_department']);
    $t->index(['message_id']);
});
```

Server-side parser scans `message_text` on send for `@username`, `@all`,
`@role-code`, and `@dept-name` patterns and inserts rows accordingly.
Frontend uses these rows to render highlights + override mute logic. All
four mention types override the recipient's mute / snooze setting.

### 3.9 Add edited-tracking column to `emr_chat_messages`

Already has `edited_at`. Add a small audit trail :

```php
Schema::table('emr_chat_messages', function (Blueprint $t) {
    $t->text('original_message_text')->nullable()->after('message_text');
    $t->foreignId('deleted_by_user_id')->nullable()->after('deleted_at')
      ->constrained('shared_users')->nullOnDelete();
});
```

`original_message_text` : populated on first edit so the original is
preserved for HIPAA audit even after edits.
`deleted_by_user_id` : who soft-deleted the message (already have `deleted_at`
from SoftDeletes ; this captures the actor).

---

## 4. Model changes

### 4.1 `ChatChannel`

- Add relationships :
  - `roleTargets()` → hasMany ChatChannelRoleTarget
  - `departmentTargets()` → hasMany ChatChannelDepartmentTarget
  - `pins()` → hasMany ChatMessagePin
  - `mutes()` → hasMany ChatChannelMute
- Add scopes :
  - `scopeRoleGroup(Builder)` → channels of type role_group
  - `scopeForJobTitleAndDept(Builder, $jobTitleCode, $department)` → role-groups that auto-include this user
- Add helpers :
  - `canPin(User)` → returns bool per the permission matrix
  - `canMange(User)` → returns bool ; gates rename / role retarget / archive

### 4.2 `ChatMessage`

- Add relationships :
  - `reactions()` → hasMany ChatMessageReaction
  - `reads()` → hasMany ChatMessageRead
  - `pin()` → hasOne ChatMessagePin
  - `mentions()` → hasMany ChatMessageMention
- Update `toApiArray()` :
  - Include `reactions`: `[{ reaction: 'thumbs_up', count: 3, my_reaction: true }, ...]`
  - Include `read_count`, `total_members`, `is_pinned`
  - Include `mentions_me: bool` (computed for current viewer)
  - Include `is_edited: bool` (true when `edited_at` is set)
  - Include `can_edit: bool` (sender + within 5 min)
  - Include `can_delete: bool` (sender + always, OR channel admin)
- Add helpers :
  - `isWithinEditWindow()` → returns true if `now() - sent_at < 5 min`
  - `parseMentions()` → returns parsed `@user` / `@role` / `@all` tokens

### 4.3 New models

- `ChatChannelRoleTarget` : tiny pivot, only `belongsTo(ChatChannel)`
- `ChatChannelDepartmentTarget` : same
- `ChatMessageReaction` : `belongsTo(ChatMessage)`, `belongsTo(User)`
- `ChatMessageRead` : same
- `ChatMessagePin` : `belongsTo(ChatChannel)`, `belongsTo(ChatMessage)`, `belongsTo(User, 'pinned_by_user_id')`
- `ChatChannelMute` : `belongsTo(ChatChannel)`, `belongsTo(User)`
- `ChatMessageMention` : `belongsTo(ChatMessage)`, `belongsTo(User, 'mentioned_user_id')` (nullable)

---

## 5. Service-layer changes

### 5.1 `ChatService` extensions

```php
// Create a role-group (specialized) channel
public function createRoleGroupChannel(
    int $tenantId,
    User $createdBy,
    string $name,
    ?string $description,
    array $jobTitleCodes,        // e.g. ['rn', 'lpn']
    array $departments,          // empty = site_wide
    bool $siteWide,
): ChatChannel

// Auto-add hook : called when User.job_title or User.department changes
public function syncRoleGroupMemberships(User $user): void
{
    // Find all role_group channels in user's tenant where :
    //   user.job_title_code IS IN channel.role_targets
    //   AND (channel.site_wide = true OR user.department IS IN channel.dept_targets)
    // For each: ensure membership exists. (Backfill = automatic since the
    // membership row creates a fresh joined_at, but messages older than that
    // are still readable because we never date-gate the message query.)
    //
    // Also remove memberships from role_group channels the user no longer
    // qualifies for. Their previous messages stay (with the
    // "no longer in role" indicator computed at render time).
}

// Pin / unpin
public function pinMessage(ChatMessage $message, User $actor): ChatMessagePin
public function unpinMessage(ChatMessage $message, User $actor): void

// Reactions
public function toggleReaction(ChatMessage $message, User $actor, string $reaction): bool
// (returns true if added, false if toggled off)

// Mute / snooze
public function muteChannel(ChatChannel $channel, User $user, ?Carbon $until = null): ChatChannelMute
public function unmuteChannel(ChatChannel $channel, User $user): void

// Mentions parsing (called by send())
public function parseAndStoreMentions(ChatMessage $message): void

// Edit + delete with audit
public function editMessage(ChatMessage $message, User $actor, string $newText): ChatMessage
public function deleteMessage(ChatMessage $message, User $actor): ChatMessage
```

### 5.2 New observer : `UserJobTitleObserver`

Watches `User` model. On `updated` events, if `job_title` or `department`
changed, calls `ChatService::syncRoleGroupMemberships($user)`.

### 5.3 Existing call sites that need to invoke the new observer

- `UserProvisioningController::store()` — when a new user is created with a job_title
- `UserProvisioningController::update()` — when title or dept changes
- `JobTitleController` — when a title is renamed (cascade ?). Probably not needed if we use code as the FK ; renaming a label doesn't break.

---

## 6. Controller endpoints

All under `/chat` prefix, all auth-gated, all tenant-scoped via existing membership checks.

### 6.1 Channel management (new)

- `POST /chat/role-group-channels` : create a specialized channel.
  - Gates : `User.role === 'admin'` only.
  - Body : `name`, `description?`, `job_title_codes[]`, `departments[]?`, `site_wide?`
  - On create : populates targets pivots, runs initial membership sync for all matching users.
  - Audit log : `chat.channel_created`.

- `PATCH /chat/role-group-channels/{channel}` : edit name / description / role+dept targets.
  - Gates : current admin of the targeted department(s).
  - On retarget : re-syncs memberships (new joins, plus removes anyone no longer matching).
  - Audit : `chat.channel_updated` with diff in metadata.

- `DELETE /chat/role-group-channels/{channel}` : archive (sets `is_active=false`).
  - Gates : same as edit.
  - Soft archive only ; never hard-delete (HIPAA retention).

- `POST /chat/group-dm-channels` : create a multi-user group DM.
  - Body : `name?` (optional), `member_user_ids[]` (≥ 2)
  - Any user can create. Tenant-scoped : all members must be in caller's effective tenant.
  - Audit : `chat.channel_created` (but with `creator_role = standard` for distinguishing).

### 6.2 Message-level features (new)

- `POST /chat/channels/{channel}/messages/{message}/react` : add reaction. Body : `reaction` (one of the 5 codes).
- `DELETE /chat/channels/{channel}/messages/{message}/react` : remove reaction. Body : `reaction`.
- `POST /chat/channels/{channel}/messages/{message}/read` : mark as read. (Triggered by frontend Intersection Observer.) Idempotent — no-op if already read.
- `PATCH /chat/channels/{channel}/messages/{message}` : edit (within 5-min window).
- `DELETE /chat/channels/{channel}/messages/{message}` : soft delete.
- `POST /chat/channels/{channel}/messages/{message}/pin` : pin (permission-gated).
- `DELETE /chat/channels/{channel}/messages/{message}/pin` : unpin.

### 6.3 Read-receipt detail panel (new)

- `GET /chat/channels/{channel}/messages/{message}/details` : returns full receipt + reaction roster.
  - Response : `{ sent_at, reads: [{user_id, name, read_at}], reactions: [{user_id, name, reaction, reacted_at}], total_members }`
  - Used for the "click-message-to-see-timestamps" panel.

### 6.4 Mute / snooze (new)

- `POST /chat/channels/{channel}/mute` : mute (optional `snoozed_until`).
- `DELETE /chat/channels/{channel}/mute` : unmute.

### 6.5 Search (new)

- `GET /chat/channels/{channel}/search` : in-channel message search.
  - Query : `q` (string, ≥ 2 chars), `limit` (default 30), `before_id` (cursor)
  - Backend : `ILIKE '%q%'` on `message_text`, with sender + sent_at returned.
  - V1 limitation : no full-text search ; fine at demo scale.
  - Future v2 : add Postgres `tsvector` column with GIN index.

### 6.6 Pinned-message panel (new)

- `GET /chat/channels/{channel}/pins` : list all pinned messages with their pinner.
- `POST /chat/channels/{channel}/messages/{message}/pin?override=1` : pin past the 50-cap. Permission-gated to channel admins only ; writes a `chat.pin_cap_override` audit row in addition to the standard pin row.

### 6.7 Channel Settings panel (new)

- `GET /chat/channels/{channel}/settings` : returns the channel's current
  config (name, description, members, role/dept targets if applicable, mute
  state for the caller) AND the audit timeline filtered to this channel's
  events. Used by the Channel Settings drawer in the UI.

### 6.8 Group DM management (new)

- `PATCH /chat/group-dm-channels/{channel}` : rename. Body : `name`. Any
  member can call. Audit : `chat.channel_renamed` with old/new in metadata.
- `POST /chat/group-dm-channels/{channel}/members` : add member. Body :
  `user_id` (must be in caller's effective tenant). Any member can call.
  Audit : `chat.member_added_by_user`.
- `DELETE /chat/group-dm-channels/{channel}/members/{user}` : remove member.
  Any member can call (including self-leave). Audit :
  `chat.member_removed_by_user` for cross-removal, `chat.member_self_left`
  for self.

### 6.7 Updates to existing endpoints

- `GET /chat/channels` : extend response to include :
  - `is_muted: bool`, `snoozed_until: timestamp?`
  - `unread_mentions_count: int` (separate from total unread, drives the @-bubble badge)
  - For role_group channels : `targets: { roles: [], departments: [], site_wide: bool }`
- `POST /chat/channels/{channel}/messages` (send) :
  - After insert, parse mentions into `emr_chat_message_mentions`
  - If any @mention hits a muted user, force a notification through anyway

---

## 7. Real-time events (Reverb)

New broadcasts, each on the existing `private-chat.{channelId}` channel :

| Event | Triggered by | Payload |
|---|---|---|
| `MessageReacted` | reaction add/remove | `{ message_id, user_id, reaction, action: 'added'|'removed' }` |
| `MessageRead` | first read of a message | `{ message_id, user_id, read_at }` |
| `MessageEdited` | edit endpoint | `{ message_id, new_text, edited_at }` |
| `MessageDeleted` | delete endpoint | `{ message_id, deleted_by_user_id, deleted_at }` |
| `MessagePinned` | pin endpoint | `{ message_id, channel_id, pinned_by_user_id, pinned_at }` |
| `MessageUnpinned` | unpin endpoint | `{ message_id, channel_id }` |
| `ChannelMembersChanged` | role-sync hook | `{ channel_id, added: [user_ids], removed: [user_ids] }` |

**Polling fallback :** if Reverb isn't reachable (Render free tier doesn't
run the websocket worker), Vue layer polls `GET /chat/channels` and the
active channel's `messages` endpoint every 6 seconds.

---

## 8. Audit logging

New `shared_audit_logs` actions :

| Action | When | Resource type / id |
|---|---|---|
| `chat.channel_created` | Role-group / group-DM creation | `chat_channel` / id |
| `chat.channel_updated` | Role-group retargeting | `chat_channel` / id |
| `chat.channel_renamed` | Group-DM rename | `chat_channel` / id |
| `chat.channel_archived` | Channel set inactive | `chat_channel` / id |
| `chat.message_sent` | Send (already exists ?) — confirm | `chat_message` / id |
| `chat.message_read` | First-read receipt (PHI access) | `chat_message` / id |
| `chat.message_edited` | Edit within 5-min window | `chat_message` / id |
| `chat.message_deleted` | Soft delete | `chat_message` / id |
| `chat.message_pinned` | Pin | `chat_message` / id |
| `chat.message_unpinned` | Unpin | `chat_message` / id |
| `chat.pin_cap_override` | Admin pinned past the 50-cap via `Pin anyway` | `chat_channel` / id |
| `chat.broadcast_pin_acknowledged` | Exec confirmed PHI dialog when pinning to All Staff | `chat_channel` / id |
| `chat.member_added_by_role` | Auto-add via JobTitle / department change | `chat_channel` / id |
| `chat.member_removed_by_role` | Auto-remove via JobTitle / department change | `chat_channel` / id |
| `chat.member_added_by_user` | Manual add to a group DM | `chat_channel` / id |
| `chat.member_removed_by_user` | Manual remove from a group DM | `chat_channel` / id |
| `chat.member_self_left` | User left a group DM voluntarily | `chat_channel` / id |
| `chat.it_admin_override` | An `it_admin` admin took an action on a channel they don't directly manage | `chat_channel` / id |

The Channel Settings panel for any channel queries
`shared_audit_logs` filtered to `resource_type='chat_channel' AND
resource_id=<channel.id>` and renders the events as a chronological
timeline — names, timestamps, and actors visible to all current
members.

---

## 9. Frontend (Vue) component breakdown

### 9.1 New structure for `Chat/Index.vue`

Split the current 614-line monolith into :

```
Chat/Index.vue                    — page shell, route handler, state
Chat/components/ChannelList.vue   — left panel, grouped + collapsible
Chat/components/ChannelGroup.vue  — single collapsible group with header + badge
Chat/components/ChannelRow.vue    — single channel link with unread + mute status
Chat/components/MessageThread.vue — right panel : header + message list + composer
Chat/components/MessageRow.vue    — single message : avatar + content + reactions + receipts
Chat/components/MessageComposer.vue — textarea + @mention picker + emoji picker + urgent toggle
Chat/components/ReactionBar.vue   — emoji palette popover
Chat/components/ReceiptModal.vue  — click-message-to-see-receipts modal
Chat/components/PinPanel.vue      — pinned messages drawer
Chat/components/SearchPanel.vue   — in-channel search drawer
Chat/components/MentionAutocomplete.vue — typeahead for @user / @role / @dept / @all
Chat/components/MuteMenu.vue      — mute / snooze submenu
Chat/components/CreateRoleGroupModal.vue — admin's specialized-channel creator (with backfill warning)
Chat/components/CreateGroupDmModal.vue   — anyone's group-DM creator
Chat/components/ChannelSettingsDrawer.vue — name + members + audit timeline (group DMs + role-groups for admins)
Chat/components/PinCapOverrideDialog.vue  — 50-pin block-with-override prompt
Chat/components/PhiPinWarningDialog.vue   — broadcast-pin PHI confirmation
```

### 9.2 Sort order for the channel list

```
Specialized           ← role_group
Department            ← department
Broadcast             ← broadcast
Participant Care      ← participant_idt   (NEW POSITION — see open question)
Direct Messages       ← direct + group_dm (combined into one section)
```

Each group is a collapsible `<details>` element with an accessible
header. Collapsed state persists in `localStorage` per user. Each
collapsed group shows a badge with `(unread / urgent / mentions)`.

### 9.3 Real-time wiring

- Existing `private-chat.{channelId}` Echo subscription stays.
- New event handlers added for the seven new broadcasts above.
- Polling fallback uses `setInterval(6000)` ; cancels on Reverb connect.

### 9.4 Read-receipt observer

Each `MessageRow.vue` registers an `IntersectionObserver` on its DOM
node. When >50 % visible for >1 s, fires `POST .../messages/{id}/read`
(debounced + idempotent server-side).

### 9.5 @mention rendering

- Server returns mentions as parsed tokens in the message API response.
- Frontend renders the four mention forms with distinct chip styling :
  - `@First Last` — solid blue user pill
  - `@RN` (or any JobTitle code) — outlined indigo role pill
  - `@primary-care` (or any department) — outlined teal dept pill
  - `@all` — solid amber attention pill (loudest visual weight)
- Mentions of the current user get a left-border highlight on the message
  row + a small flag in the message header (`Mentioned you`).
- The MentionAutocomplete typeahead surfaces all four forms when the user
  types `@`, with the four categories grouped (Users / Roles / Depts / All).

---

## 10. Test plan

| Category | Count | Examples |
|---|---|---|
| Migrations | 1 | All 8 migrations apply + roll back cleanly |
| ChatChannel model | 4 | Scopes, target relationships, canPin per channel type, displayName for role_group |
| ChatMessage model | 5 | toApiArray includes reactions/reads/pin/mentions/can_edit, isWithinEditWindow boundary |
| Reactions | 4 | Toggle adds, toggle removes, unique constraint blocks duplicate, real-time event fires |
| Reads | 3 | First-read writes audit row, second-read no-op, real-time event fires |
| Edit | 4 | Within 5-min ok, outside 5-min 422, original_message_text preserved, real-time event fires |
| Delete | 3 | Sender + admin only, soft delete preserves text, real-time event fires |
| Pins | 7 | Permission matrix per channel type, 50-cap blocks regular member, override allowed for admin, override audit row written, edit propagates, deleted message auto-clears, audit fires |
| Mute | 4 | Notification suppressed, @mention overrides, snooze expiry resumes, indefinite mute persists |
| @mentions | 7 | @user parsed, @all parsed, @role parsed, @dept parsed, override mute on all 4, multiple mentions in one message, autocomplete returns all 4 categories |
| Group DM management | 5 | Rename audited, member-add audited, member-remove audited, self-leave audited, settings panel returns timeline |
| IT admin override | 3 | it_admin can manage role-group they don't own, override audit row written, regular dept admin in another dept gets 403 |
| Auto-add hook | 6 | New user with matching role joins, dept change adds/removes, retarget sync, race when 100+ users qualify, audit logs fire, observer doesn't recurse |
| Role-group create | 3 | Multi-dept multi-role works, site-wide works, regular user gets 403 |
| Group DM | 2 | Anyone creates with N members, 1-member rejected |
| Search | 2 | ILIKE finds matches, only my channels searchable |
| Pin permission integration | 4 | Each channel type's pin permission tested |
| End-to-end | 4 | Full Reverb-driven exchange, polling fallback works, mute survives logout, history backfill on auto-add |

**Total :** ≈ 70 new tests after the section-11 answers were locked in (added IT admin override + dept mention + group DM management + pin-cap override). With the existing chat test count we'd be at ~90 chat-specific tests.

---

## 11. Resolved decisions (TJ confirmed 2026-04-30)

All 7 open questions have been answered. Locking the answers here so the
build sequence references one canonical spec.

### 11.1 `participant_idt` channel placement → APPROVED

Sort between **Broadcast** and **Direct Messages**. Final hierarchy :

1. Specialized (role_group) → top
2. Department
3. Broadcast
4. Participant Care Teams (existing `participant_idt`)
5. Direct + Group DM → bottom

### 11.2 "Department admin" definition → tiered, audit-logged

| Action | Who can perform |
|---|---|
| Create role-group channel **for one or more departments** | (a) admin of any one of the target depts (`role='admin' AND department IN <targets>`), OR (b) executive department, OR (c) `it_admin` admin (override path, audit-logged), OR (d) `role='super_admin'` |
| Create role-group channel **site-wide** | Executive, `it_admin` admin, or `super_admin` only. Site-wide is broad enough that dept admins shouldn't unilaterally have it. |
| Edit / retarget / archive an existing role-group channel | Same set as create, scoped to current targeting |
| Pin a message in a role-group / department / participant_idt channel | Per the matrix in section 1 (admin of target dept), with executive + it_admin admin + super_admin always allowed |
| Pin a message in a broadcast channel | Executive or super_admin only |

Reasoning : matches the existing app pattern (`it_admin` has override on
grievances, credential management, etc.). The audit log makes every
`it_admin` override action visible if ever abused. Day-to-day workflow
stays with department leadership ; break-glass scenarios are covered.

### 11.3 `@dept-name` shorthand → APPROVED

Mention syntax expanded to four forms :

- `@user.name` → mention a specific user by username
- `@all` (or `@channel`) → mention every member of the channel
- `@role-code` → mention everyone in the channel with that JobTitle (e.g. `@RN`)
- `@dept-name` → mention everyone in the channel from that department (e.g. `@primary-care`)

All four override the recipient's mute / snooze setting. Multiple mentions
in a single message are allowed and parsed independently. The mentions
table grows by one row per mention (mentioned_user_id OR mentioned_role_code
OR mentioned_department, with `is_at_all` for `@all`).

### 11.4 PHI-pin-to-broadcast warning → APPROVED

Confirmation dialog when an executive pins to the All Staff channel :

> **Pin to All Staff?**
>
> This message will be visible to every active staff user in the tenant
> (across all departments and sites). Confirm it does not contain
> protected health information without a minimum-necessary justification.
>
> [ Cancel ] [ Pin to All Staff ]

The pin action writes a `chat.broadcast_pin_acknowledged` audit row
in addition to the standard `chat.message_pinned` row.

### 11.5 History-backfill warning at role-group creation → APPROVED

Modal copy at `Create specialized chat` time :

> **Heads up : full history visible to future members**
>
> This is a role-based group chat. Anyone who later joins your team with
> the matching JobTitle (or transfers into a target department) will be
> automatically added — and will see the entire history of this
> conversation from the moment it was created.
>
> If the conversation will contain PHI or sensitive clinical context,
> confirm this is appropriate before continuing.
>
> [ Cancel ] [ Create channel ]

### 11.6 Group DM management → APPROVED with full audit history

Group DMs (user-created multi-user channels) support the following after
creation :

- **Rename** by any current member
- **Add member** by any current member ; new members see full history
  (consistent with role-group rule)
- **Remove member** by any current member (anyone can remove anyone,
  including themselves) ; removed users lose access immediately but the
  message history they posted stays attributed
- **Leave** by any current member (self-remove)

**Every channel-level action is audit-logged with full timestamp + actor,
and exposed to members via a "Channel Settings" panel.** The settings
panel shows :

- Current name
- Current members
- A scrollable timeline : *"Renamed to 'X' by Alice on Mon Apr 30 14:30"*,
  *"Bob added Carol on Tue Mar 4 09:12"*, *"Dave left on Wed Mar 5 11:00"*

Storage : these events go into `shared_audit_logs` with action codes
`chat.channel_renamed`, `chat.member_added_by_user`,
`chat.member_removed_by_user`, `chat.member_self_left`. The settings
panel queries `shared_audit_logs` filtered by `resource_type='chat_channel'`
+ `resource_id=<channel.id>` for that channel's history.

### 11.7 50-pin soft cap → block with override

When a channel reaches 50 pins :

- **Default behaviour** : the next pin attempt is blocked with notice :
  > *"This channel has reached its 50-pin limit. Unpin something first,
  > or override to add this pin anyway."*
- **Override** : a `[ Pin anyway ]` button is available, but only for
  users with **edit-channel rights** (the same set as "who can pin" in
  the matrix above). Regular members see only `[ Cancel ]`.
- **Override is audit-logged** with action `chat.pin_cap_override` so
  abuse is visible.
- **No auto-rotation.** Pins only leave when an admin actively unpins
  them.

Rationale : auto-rotation can silently lose important messages.
Block-with-override puts the choice in front of someone who has earned
the authority to make it.

---

## 12. Build sequence

Estimated 2 focused sessions of work, ~6-10 hours total. Sequence to
minimize broken intermediate states :

### Session 1 : foundation (~3-4 h)

1. Migrations (all 8) : new tables + extend channel_type
2. Models + relationships : 7 new + 2 extended
3. ChatService extensions : create / sync / pin / react / mute / mentions
4. UserJobTitleObserver + wiring into UserProvisioningController
5. Test : migrations, models, service unit tests, observer tests
6. End of session : DB schema + service layer 100 % done, no UI changes yet

### Session 2 : surface (~3-4 h)

7. Controller endpoints : 16 new + 1 updated existing
8. Reverb events : 7 new
9. Vue component split : 13 new components, gut-rebuild Chat/Index.vue
10. CreateRoleGroupModal + CreateGroupDmModal flows
11. ReceiptModal + PinPanel + SearchPanel drawers
12. MentionAutocomplete typeahead
13. Polling-fallback when Reverb unavailable
14. Test : feature tests, end-to-end smoke through the demo seed
15. End of session : full chat v2 live in dev

### Session 3 (optional, ~1-2 h) : polish

16. Audit-log every action that touched `shared_audit_logs`
17. Re-run full test suite, fix any regressions
18. Update demo seeders to add 1-2 sample role-group channels
19. Update `docs/ARCHITECTURE.md` with the new chat patterns
20. Final feedback memo + MEMORY.md index update

---

## 13. Out-of-scope for v2 (parking lot)

- **Attachments** (lab PDFs, photos) : v3
- **Cross-channel search** : v3
- **Threaded replies** : v3
- **Message-level retention beyond default 6 years** : v3
- **End-to-end encryption** : v3+ (and only matters when we go BAA-eligible)
- **Mobile push notifications** : v4 (paywall item)
- **AI summarization of long threads** : v5 (post-launch)

Each of the above is straightforward to bolt on later without re-doing v2 work.
