<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityEvent extends Model
{
    use HasFactory;

    protected $table = 'emr_activity_events';

    public const CATEGORIES = ['social', 'physical', 'cognitive', 'creative', 'spiritual', 'therapeutic'];

    protected $fillable = [
        'tenant_id', 'site_id', 'title', 'category',
        'scheduled_at', 'duration_min', 'location',
        'facilitator_user_id', 'description',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function facilitator(): BelongsTo { return $this->belongsTo(User::class, 'facilitator_user_id'); }
    public function attendances(): HasMany   { return $this->hasMany(ActivityAttendance::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
