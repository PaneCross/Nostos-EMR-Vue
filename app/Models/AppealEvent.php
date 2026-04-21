<?php

// ─── AppealEvent (append-only) ────────────────────────────────────────────────
// Immutable timeline of an Appeal. DB rules block UPDATE/DELETE.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealEvent extends Model
{
    use HasFactory;

    protected $table = 'emr_appeal_events';

    // Append-only: no updated_at, no deletes.
    public const UPDATED_AT = null;

    public const EVENT_FILED                   = 'filed';
    public const EVENT_ACKNOWLEDGED            = 'acknowledged';
    public const EVENT_STATUS_CHANGED          = 'status_changed';
    public const EVENT_DECIDED                 = 'decided';
    public const EVENT_WITHDRAWN               = 'withdrawn';
    public const EVENT_EXTERNAL_REVIEW_REQUEST = 'external_review_requested';
    public const EVENT_EXTERNAL_REVIEW_OUTCOME = 'external_review_outcome';
    public const EVENT_LETTER_ISSUED           = 'letter_issued';
    public const EVENT_CLOSED                  = 'closed';

    protected $fillable = [
        'tenant_id',
        'appeal_id',
        'event_type',
        'from_status',
        'to_status',
        'narrative',
        'metadata',
        'actor_user_id',
        'occurred_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function appeal(): BelongsTo        { return $this->belongsTo(Appeal::class); }
    public function actor(): BelongsTo         { return $this->belongsTo(User::class, 'actor_user_id'); }
}
