<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralStatusHistory extends Model
{
    protected $table = 'emr_referral_status_history';

    // Append-only — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'referral_id',
        'tenant_id',
        'from_status',
        'to_status',
        'transitioned_by_user_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function transitionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transitioned_by_user_id');
    }
}
