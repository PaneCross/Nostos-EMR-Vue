<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalRequest extends Model
{
    protected $table = 'emr_portal_requests';

    public const TYPES    = ['records', 'appointment', 'contact_update', 'amendment'];
    public const STATUSES = ['pending', 'processed', 'rejected'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'from_portal_user_id',
        'request_type', 'payload', 'status',
        'processed_by_user_id', 'processed_at', 'staff_note',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
