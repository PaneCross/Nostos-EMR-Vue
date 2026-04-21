<?php

// ─── Migration: Appeals ───────────────────────────────────────────────────────
// 42 CFR §460.122 participant appeal of a service denial.
// Distinct from grievances (which are dissatisfaction expressions).
//
// Clocks:
//   type=standard    → internal decision due within 30 days of filing
//   type=expedited   → internal decision due within 72 hours of filing
// After internal decision (upheld), participant may request external/
// independent review; outcomes tracked here.
//
// Continuation of benefits: when appealing a termination/reduction of an
// ongoing service, the service continues until decision per §460.122.
// Tracked via continuation_of_benefits bool and enforced at query time
// against the related SDR / care plan goal.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->foreignId('service_denial_notice_id')->constrained('emr_service_denial_notices')->restrictOnDelete();

            $table->string('type', 20);            // standard | expedited
            $table->string('status', 40);
            $table->string('filed_by', 40);        // participant | representative | staff_on_behalf
            $table->string('filed_by_name')->nullable();
            $table->text('filing_reason')->nullable();

            $table->timestampTz('filed_at');
            $table->timestampTz('internal_decision_due_at');
            $table->timestampTz('internal_decision_at')->nullable();
            $table->foreignId('internal_decision_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->text('decision_narrative')->nullable();

            $table->boolean('continuation_of_benefits')->default(false);

            // External / independent review path (post-internal-upheld)
            $table->timestampTz('external_review_requested_at')->nullable();
            $table->string('external_review_outcome', 40)->nullable();  // pending | upheld | overturned | partially_overturned | withdrawn
            $table->timestampTz('external_review_outcome_at')->nullable();
            $table->text('external_review_narrative')->nullable();

            $table->foreignId('acknowledgment_pdf_document_id')->nullable()->constrained('emr_documents')->nullOnDelete();
            $table->foreignId('decision_pdf_document_id')->nullable()->constrained('emr_documents')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'internal_decision_due_at']);
            $table->index(['participant_id']);
        });

        DB::statement("
            ALTER TABLE emr_appeals
            ADD CONSTRAINT emr_appeals_type_check
            CHECK (type IN ('standard', 'expedited'))
        ");

        DB::statement("
            ALTER TABLE emr_appeals
            ADD CONSTRAINT emr_appeals_status_check
            CHECK (status IN (
                'received',
                'acknowledged',
                'under_review',
                'decided_upheld',
                'decided_overturned',
                'decided_partially_overturned',
                'withdrawn',
                'external_review_requested',
                'closed'
            ))
        ");

        DB::statement("
            ALTER TABLE emr_appeals
            ADD CONSTRAINT emr_appeals_filed_by_check
            CHECK (filed_by IN ('participant', 'representative', 'staff_on_behalf'))
        ");

        DB::statement("
            ALTER TABLE emr_appeals
            ADD CONSTRAINT emr_appeals_external_outcome_check
            CHECK (external_review_outcome IS NULL OR external_review_outcome IN (
                'pending', 'upheld', 'overturned', 'partially_overturned', 'withdrawn'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_appeals');
    }
};
