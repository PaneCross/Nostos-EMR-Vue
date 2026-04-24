<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantPortalUser extends Model
{
    protected $table = 'emr_participant_portal_users';

    public const PROXY_SCOPES = ['full', 'limited'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'participant_contact_id',
        'proxy_scope', 'email', 'phone', 'password', 'is_active',
        'last_login_at', 'portal_consent_record_id',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }

    public function isProxy(): bool { return $this->proxy_scope !== null; }
    public function isFullAccess(): bool { return ! $this->isProxy() || $this->proxy_scope === 'full'; }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
