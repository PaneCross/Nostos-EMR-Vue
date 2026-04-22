<?php

// ─── QapiAnnualEvaluation ─────────────────────────────────────────────────────
// 42 CFR §460.200 annual QAPI evaluation artifact. Compiles a tenant's QAPI
// projects + outcomes for the calendar year into a PDF for governing body
// review.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QapiAnnualEvaluation extends Model
{
    use HasFactory;

    protected $table = 'emr_qapi_annual_evaluations';

    protected $fillable = [
        'tenant_id',
        'year',
        'generated_at',
        'generated_by_user_id',
        'pdf_path',
        'pdf_size_bytes',
        'governing_body_reviewed_at',
        'governing_body_reviewed_by_user_id',
        'governing_body_notes',
        'summary_snapshot',
    ];

    protected $casts = [
        'generated_at'               => 'datetime',
        'governing_body_reviewed_at' => 'datetime',
        'summary_snapshot'           => 'array',
        'year'                       => 'integer',
    ];

    public function tenant(): BelongsTo            { return $this->belongsTo(Tenant::class); }
    public function generatedBy(): BelongsTo       { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function governingBodyReviewer(): BelongsTo { return $this->belongsTo(User::class, 'governing_body_reviewed_by_user_id'); }

    public function isReviewed(): bool
    {
        return $this->governing_body_reviewed_at !== null;
    }
}
