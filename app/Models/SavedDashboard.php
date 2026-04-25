<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedDashboard extends Model
{
    use HasFactory;

    protected $table = 'emr_saved_dashboards';

    protected $fillable = [
        'tenant_id', 'owner_user_id', 'title', 'description',
        'widgets', 'is_shared',
    ];

    protected $casts = [
        'widgets'   => 'array',
        'is_shared' => 'boolean',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
