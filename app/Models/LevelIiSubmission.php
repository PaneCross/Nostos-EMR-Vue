<?php

// ─── LevelIiSubmission ────────────────────────────────────────────────────────
// CMS PACE Level I / Level II quarterly submission artifact.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LevelIiSubmission extends Model
{
    use HasFactory;

    protected $table = 'emr_level_ii_submissions';

    protected $fillable = [
        'tenant_id',
        'year',
        'quarter',
        'generated_at',
        'generated_by_user_id',
        'csv_path',
        'csv_size_bytes',
        'marked_cms_submitted_at',
        'marked_cms_submitted_by_user_id',
        'marked_cms_submitted_notes',
        'indicators_snapshot',
    ];

    protected $casts = [
        'generated_at'            => 'datetime',
        'marked_cms_submitted_at' => 'datetime',
        'indicators_snapshot'     => 'array',
        'year'                    => 'integer',
        'quarter'                 => 'integer',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function markedSubmittedBy(): BelongsTo { return $this->belongsTo(User::class, 'marked_cms_submitted_by_user_id'); }

    public function isSubmitted(): bool
    {
        return $this->marked_cms_submitted_at !== null;
    }

    public function label(): string
    {
        return "Q{$this->quarter} {$this->year}";
    }

    /**
     * Carbon start of the period covered by this submission.
     * Phase 3 uses calendar quarters (Q1=Jan-Mar, etc.).
     */
    public function periodStart(): \Illuminate\Support\Carbon
    {
        $month = (($this->quarter - 1) * 3) + 1;
        return \Illuminate\Support\Carbon::createFromDate($this->year, $month, 1)->startOfDay();
    }

    public function periodEnd(): \Illuminate\Support\Carbon
    {
        return $this->periodStart()->copy()->addMonths(3)->subSecond();
    }
}
