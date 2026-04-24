<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentTemplate extends Model
{
    protected $table = 'emr_consent_templates';

    public const STATUSES = ['draft', 'approved', 'archived'];

    protected $fillable = [
        'tenant_id', 'consent_type', 'version', 'title', 'body',
        'status', 'approved_by_user_id', 'approved_at',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeApproved($q)          { return $q->where('status', 'approved'); }
}
