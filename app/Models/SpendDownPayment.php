<?php

// ─── SpendDownPayment ─────────────────────────────────────────────────────────
// Medicaid spend-down / share-of-cost payment (or equivalent credit) applied
// toward a participant's monthly obligation. Phase 7 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpendDownPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_spend_down_payments';

    public const METHODS = [
        'check', 'cash', 'eft', 'money_order',
        'payroll_deduction', 'medical_expense_credit', 'waiver', 'other',
    ];

    public const METHOD_LABELS = [
        'check'                  => 'Check',
        'cash'                   => 'Cash',
        'eft'                    => 'Electronic Funds Transfer',
        'money_order'            => 'Money Order',
        'payroll_deduction'      => 'Payroll Deduction',
        'medical_expense_credit' => 'Medical Expense Credit',
        'waiver'                 => 'Waiver',
        'other'                  => 'Other',
    ];

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'amount',
        'paid_at',
        'period_month_year',
        'payment_method',
        'reference_number',
        'notes',
        'receipt_document_id',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class); }
    public function recordedBy(): BelongsTo   { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function receipt(): BelongsTo      { return $this->belongsTo(Document::class, 'receipt_document_id'); }

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeForPeriod(Builder $q, string $periodYm): Builder
    {
        return $q->where('period_month_year', $periodYm);
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->payment_method] ?? $this->payment_method;
    }
}
