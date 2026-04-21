<?php

// ─── ServiceDenialNotice ──────────────────────────────────────────────────────
// CMS-style denial letter evidence (42 CFR §460.122).
// Append-only: a notice, once issued, is audit evidence. Cannot be deleted.
// The linked PDF lives in emr_documents (pdf_document_id).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceDenialNotice extends Model
{
    use HasFactory;

    protected $table = 'emr_service_denial_notices';

    public const DELIVERY_METHODS = [
        'mail',
        'in_person',
        'email',
        'secure_portal',
        'phone_documented',
    ];

    // Standard CMS appeal deadline window for denials. §460.122 requires
    // written notice include deadline; 30 days is the non-expedited CMS rule.
    public const APPEAL_DEADLINE_DAYS = 30;

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'sdr_id',
        'denial_record_id',
        'reason_code',
        'reason_narrative',
        'issued_by_user_id',
        'issued_at',
        'delivery_method',
        'appeal_deadline_at',
        'pdf_document_id',
    ];

    protected $casts = [
        'issued_at'          => 'datetime',
        'appeal_deadline_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function sdr(): BelongsTo
    {
        return $this->belongsTo(Sdr::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    /** True if the appeal-filing window is still open (no appeal may be filed after this). */
    public function appealWindowOpen(): bool
    {
        return $this->appeal_deadline_at->isFuture();
    }
}
