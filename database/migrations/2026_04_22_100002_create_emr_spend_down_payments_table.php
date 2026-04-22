<?php

// ─── Migration: Spend-down payment ledger ─────────────────────────────────────
// One row per payment or earned expense credit. The sum of rows for a
// (participant, period) must reach the monthly obligation before Medicaid
// coverage activates / capitation can be billed for the period.
//
// Payment methods supported:
//   check | cash | eft | money_order | payroll_deduction | medical_expense_credit | waiver
//
// The "medical_expense_credit" method is used in some states where incurred
// medical expenses count toward the spend-down threshold (no literal cash).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_spend_down_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('paid_at');

            // Period this payment is applied toward ('YYYY-MM' string matches
            // CapitationRecord.month_year convention).
            $table->string('period_month_year', 7);          // e.g. '2026-04'

            $table->string('payment_method', 40);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();

            // Optional receipt/document reference (uploaded as legal Document row).
            $table->foreignId('receipt_document_id')->nullable()->constrained('emr_documents')->nullOnDelete();

            $table->foreignId('recorded_by_user_id')->constrained('shared_users')->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletes();

            $table->index(['tenant_id', 'participant_id', 'period_month_year'], 'emr_spend_down_period_idx');
        });

        DB::statement("
            ALTER TABLE emr_spend_down_payments
            ADD CONSTRAINT emr_spend_down_method_check
            CHECK (payment_method IN (
                'check',
                'cash',
                'eft',
                'money_order',
                'payroll_deduction',
                'medical_expense_credit',
                'waiver',
                'other'
            ))
        ");

        DB::statement("
            ALTER TABLE emr_spend_down_payments
            ADD CONSTRAINT emr_spend_down_period_check
            CHECK (period_month_year ~ '^[0-9]{4}-[0-9]{2}\$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_spend_down_payments');
    }
};
