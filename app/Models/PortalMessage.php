<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalMessage extends Model
{
    protected $table = 'emr_portal_messages';

    protected $fillable = [
        'tenant_id', 'participant_id',
        'from_portal_user_id', 'from_staff_user_id',
        'subject', 'body', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function fromStaff(): BelongsTo   { return $this->belongsTo(User::class, 'from_staff_user_id'); }

    public function isFromPortal(): bool { return $this->from_portal_user_id !== null; }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
