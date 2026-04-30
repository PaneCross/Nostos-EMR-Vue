<?php

// ─── ChatMessage Model ────────────────────────────────────────────────────────
// A single message in a chat channel.
//
// HIPAA 6-year retention : messages are NEVER hard-deleted.
// Soft-deleted messages render as "This message was deleted" in the UI.
// The original message_text is preserved in the DB for audit/legal purposes.
//
// urgent priority messages show a red left border + "URGENT" badge.
//
// Edit window : 5 minutes from sent_at. After that, edit returns 422.
// On first edit, the original message_text is preserved in
// original_message_text — subsequent edits don't update that column, so
// the historical record of "what was first sent" survives all edits.
//
// See docs/plans/chat_v2_plan.md §3.9 + §4.2.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_chat_messages';

    // No created_at/updated_at : we use sent_at and edited_at directly.
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    /** Edit window in minutes after sent_at. Beyond this, edits return 422. */
    public const EDIT_WINDOW_MINUTES = 5;

    protected $fillable = [
        'channel_id',
        'sender_user_id',
        'message_text',
        'original_message_text',
        'priority',
        'sent_at',
        'edited_at',
        'deleted_by_user_id',
    ];

    protected $casts = [
        'sent_at'   => 'datetime',
        'edited_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatMessageReaction::class, 'message_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ChatMessageRead::class, 'message_id');
    }

    public function pin(): HasOne
    {
        return $this->hasOne(ChatMessagePin::class, 'message_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ChatMessageMention::class, 'message_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** True when this message has been soft-deleted. */
    public function isDeleted(): bool
    {
        return $this->trashed();
    }

    /** True when this message was sent with urgent priority. */
    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    /**
     * Edit window check : true if the message can still be edited by its
     * sender. Soft-deleted messages cannot be edited.
     */
    public function isWithinEditWindow(): bool
    {
        if ($this->isDeleted() || ! $this->sent_at) {
            return false;
        }
        return $this->sent_at->diffInMinutes(now()) < self::EDIT_WINDOW_MINUTES;
    }

    /**
     * API-safe representation. Deleted messages expose only the placeholder
     * text — never the original message_text — preserving HIPAA boundaries
     * while showing the conversation thread structure.
     *
     * The $viewer parameter customises the response :
     *   - my_reaction : whether the viewer themselves has each reaction
     *   - mentions_me : whether any mention row references the viewer
     *   - can_edit    : sender + within edit window
     *   - can_delete  : sender, channel admin, or super-admin
     */
    public function toApiArray(?User $viewer = null): array
    {
        $isDeleted = $this->isDeleted();

        // Group reactions by code with counts. We rely on the caller to
        // eager-load reactions to avoid N+1 ; if not, we pay the query.
        $reactionsByCode = $this->reactions->groupBy('reaction');
        $reactionPayload = [];
        foreach (ChatMessageReaction::REACTION_CODES as $code) {
            $rows = $reactionsByCode[$code] ?? collect();
            if ($rows->isNotEmpty()) {
                $reactionPayload[] = [
                    'reaction'    => $code,
                    'count'       => $rows->count(),
                    'my_reaction' => $viewer
                        ? $rows->contains('user_id', $viewer->id)
                        : false,
                ];
            }
        }

        // Mentions : flatten the four kinds into a single array the
        // frontend can iterate over for chip rendering.
        $mentionPayload = $this->mentions->map(function (ChatMessageMention $m) {
            return [
                'kind'                 => $m->kind(),
                'mentioned_user_id'    => $m->mentioned_user_id,
                'mentioned_role_code'  => $m->mentioned_role_code,
                'mentioned_department' => $m->mentioned_department,
                'is_at_all'            => $m->is_at_all,
            ];
        })->all();

        $mentionsMe = $viewer
            ? $this->mentions->contains('mentioned_user_id', $viewer->id)
            : false;

        // Member count : how many people might have read this — drives the
        // "8 of 12 read" UI in the receipts modal.
        $totalMembers = $this->channel
            ? $this->channel->memberships()->count()
            : 0;

        return [
            'id'             => $this->id,
            'channel_id'     => $this->channel_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_name'    => $this->sender?->fullName(),
            'sender_initials'=> $this->sender
                ? strtoupper(substr($this->sender->first_name, 0, 1) . substr($this->sender->last_name, 0, 1))
                : '??',
            'message_text'   => $isDeleted ? null : $this->message_text,
            'is_deleted'     => $isDeleted,
            'is_edited'      => $this->edited_at !== null,
            'priority'       => $this->priority,
            'sent_at'        => $this->sent_at?->toIso8601String(),
            'edited_at'      => $this->edited_at?->toIso8601String(),
            'reactions'      => $reactionPayload,
            'read_count'     => $this->reads->count(),
            'total_members'  => $totalMembers,
            'is_pinned'      => $this->pin !== null,
            'mentions'       => $mentionPayload,
            'mentions_me'    => $mentionsMe,
            'can_edit'       => $viewer
                && $this->sender_user_id === $viewer->id
                && $this->isWithinEditWindow(),
            'can_delete'     => $viewer
                && (
                    $this->sender_user_id === $viewer->id
                    || ($this->channel && $this->channel->canManage($viewer))
                    || $viewer->isSuperAdmin()
                    || $viewer->isDeptSuperAdmin()
                ),
        ];
    }
}
