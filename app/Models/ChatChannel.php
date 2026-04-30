<?php

// ─── ChatChannel Model ────────────────────────────────────────────────────────
// A conversation channel. Six types after Chat v2 :
//
//   direct          : DM between exactly two users (user-created)
//   group_dm        : Group DM with 3+ user-chosen members (user-created)
//   department      : One per department, all dept users auto-joined at tenant setup
//   role_group      : "Specialized" channel targeting one or more JobTitles, scoped
//                     to one or more departments OR site-wide. Auto-add hook fires
//                     when a User's job_title or department changes.
//   participant_idt : Per-participant IDT care-team channel, auto-created on enrollment
//   broadcast       : Org-wide ; all users auto-joined at tenant setup
//
// Soft deletes are NOT used : channels are is_active=false when retired.
// Messages use soft deletes for HIPAA 6-year retention.
//
// Permission rules for managing role_group / department / broadcast channels
// live in canManage() / canPin() — see docs/plans/chat_v2_plan.md §11.2.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatChannel extends Model
{
    use HasFactory;

    protected $table = 'emr_chat_channels';

    // No updated_at : channels don't change after creation. Renames flow
    // through audit-logged controller endpoints, not Eloquent updates.
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'channel_type',
        'name',
        'description',
        'site_wide',
        'participant_id',
        'created_by_user_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'site_wide' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** All membership records for this channel. */
    public function memberships(): HasMany
    {
        return $this->hasMany(ChatMembership::class, 'channel_id');
    }

    /** Users who are members of this channel (pivot: emr_chat_memberships). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'emr_chat_memberships',
            'channel_id',
            'user_id'
        )->withPivot(['joined_at', 'last_read_at']);
    }

    /** All messages in this channel (including soft-deleted for audit). */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'channel_id');
    }

    /** JobTitle codes this role_group channel targets (empty for other types). */
    public function roleTargets(): HasMany
    {
        return $this->hasMany(ChatChannelRoleTarget::class, 'channel_id');
    }

    /** Department slugs this role_group channel targets (empty for site_wide). */
    public function departmentTargets(): HasMany
    {
        return $this->hasMany(ChatChannelDepartmentTarget::class, 'channel_id');
    }

    /** Pinned messages in this channel. */
    public function pins(): HasMany
    {
        return $this->hasMany(ChatMessagePin::class, 'channel_id');
    }

    /** Mute rows for this channel (one per muting user). */
    public function mutes(): HasMany
    {
        return $this->hasMany(ChatChannelMute::class, 'channel_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Active channels only. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Channels the given user is a member of. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id));
    }

    /** Role-group (specialized) channels only. */
    public function scopeRoleGroup(Builder $query): Builder
    {
        return $query->where('channel_type', 'role_group');
    }

    /**
     * Role-group channels that auto-include a user with the given JobTitle
     * code AND department. Used by the auto-add observer when a user's
     * job_title or department changes.
     *
     * Matches when :
     *   - channel.channel_type = 'role_group'
     *   - JobTitle code is in channel.roleTargets
     *   - AND (channel.site_wide = true OR department is in channel.departmentTargets)
     */
    public function scopeForJobTitleAndDept(Builder $query, ?string $jobTitleCode, ?string $department): Builder
    {
        if ($jobTitleCode === null) {
            return $query->whereRaw('1 = 0'); // user with no job_title can't auto-join
        }

        return $query
            ->where('channel_type', 'role_group')
            ->where('is_active', true)
            ->whereHas('roleTargets', fn ($q) => $q->where('job_title_code', $jobTitleCode))
            ->where(function (Builder $q) use ($department) {
                $q->where('site_wide', true);
                if ($department !== null) {
                    $q->orWhereHas(
                        'departmentTargets',
                        fn ($dq) => $dq->where('department', $department)
                    );
                }
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Display name for the channel from the perspective of a given user.
     * DM channels have no stored name : derive from the other participant.
     */
    public function displayName(User $viewer): string
    {
        if ($this->channel_type === 'direct') {
            $other = $this->members()
                ->where('shared_users.id', '!=', $viewer->id)
                ->first();
            return $other ? $other->fullName() : 'Direct Message';
        }

        return $this->name ?? 'Channel';
    }

    /** Unread message count for a given user. */
    public function unreadCountFor(User $user): int
    {
        $membership = $this->memberships()
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            return 0;
        }

        $query = $this->messages()->withoutTrashed();

        if ($membership->last_read_at) {
            $query->where('sent_at', '>', $membership->last_read_at);
        }

        return $query->count();
    }

    /**
     * Can $actor pin / unpin in this channel ? Permission matrix per
     * docs/plans/chat_v2_plan.md §1 (Pin permission matrix).
     */
    public function canPin(User $actor): bool
    {
        // Super-admins always.
        if ($actor->isSuperAdmin() || $actor->isDeptSuperAdmin()) {
            return true;
        }

        return match ($this->channel_type) {
            'broadcast' => $actor->department === 'executive',

            'department' => $actor->role === 'admin'
                && $actor->department === $this->slugFromName(),

            'role_group' => $this->isAdminOfTargetDept($actor)
                || $actor->department === 'executive'
                || ($actor->role === 'admin' && $actor->department === 'it_admin'),

            // DMs : any member can pin.
            'direct', 'group_dm' => $this->memberships()->where('user_id', $actor->id)->exists(),

            // participant_idt : same membership rule as DMs (clinical group chat).
            'participant_idt' => $this->memberships()->where('user_id', $actor->id)->exists(),

            default => false,
        };
    }

    /**
     * Can $actor manage (rename / retarget / archive) this channel ?
     * Used by controller authorization on PATCH / DELETE operations.
     */
    public function canManage(User $actor): bool
    {
        if ($actor->isSuperAdmin() || $actor->isDeptSuperAdmin()) {
            return true;
        }

        return match ($this->channel_type) {
            'role_group' => $this->isAdminOfTargetDept($actor)
                || $actor->department === 'executive'
                || ($actor->role === 'admin' && $actor->department === 'it_admin'),

            // Group DMs : any member can manage (rename, add / remove members).
            'group_dm' => $this->memberships()->where('user_id', $actor->id)->exists(),

            // Department / broadcast / participant_idt / direct : not user-managed.
            default => false,
        };
    }

    /**
     * Is the actor an admin of any department this role_group channel targets ?
     * For site_wide channels, returns true only for executive / it_admin /
     * super-admin (already short-circuited above).
     */
    private function isAdminOfTargetDept(User $actor): bool
    {
        if ($actor->role !== 'admin') {
            return false;
        }

        if ($this->site_wide) {
            return false; // site-wide management requires exec/it_admin/super_admin
        }

        return $this->departmentTargets()
            ->where('department', $actor->department)
            ->exists();
    }

    /**
     * Department channels store the department label as the name (e.g.
     * "Primary Care"). The corresponding User.department slug is the
     * lowercased / underscored form. This converts back so canPin() can
     * compare against User.department directly.
     */
    private function slugFromName(): ?string
    {
        if (! $this->name) {
            return null;
        }
        return strtolower(str_replace(' ', '_', trim($this->name)));
    }
}
