<?php

// ─── NoteTemplate ────────────────────────────────────────────────────────────
// Phase B7. Shared + per-tenant template library for clinical notes.
// Body is Markdown with {{variable}} placeholders rendered by NoteTemplateService.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteTemplate extends Model
{
    protected $table = 'emr_note_templates';

    protected $fillable = [
        'tenant_id', 'name', 'note_type', 'department',
        'body_markdown', 'is_system', 'created_by_user_id',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by_user_id'); }

    /** Scope: templates available to a given tenant (system + own-tenant). */
    public function scopeAvailableTo($q, int $tenantId)
    {
        return $q->where(function ($q2) use ($tenantId) {
            $q2->where('is_system', true)->orWhere('tenant_id', $tenantId);
        });
    }
}
