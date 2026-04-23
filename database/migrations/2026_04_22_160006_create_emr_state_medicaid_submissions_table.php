<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.9 — State Medicaid encounter submission scaffolding ───────────
// Tracks encounter batches prepared for state Medicaid portals. Uses the
// existing StateMedicaidConfig (Phase 9C) for per-state configuration.
// Actual transmission is scaffold-only; status='staged_manual' on default
// null-adapter path, matching clearinghouse gateway pattern.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_state_medicaid_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('state_config_id')->nullable()->constrained('emr_state_medicaid_configs')->nullOnDelete();
            $t->foreignId('edi_batch_id')->nullable()->constrained('emr_edi_batches')->nullOnDelete();
            $t->string('state_code', 2);
            $t->string('submission_format', 20); // 837P|837I|custom
            $t->string('status', 30)->default('staged_manual'); // staged_manual|pending|submitted|accepted|rejected|error
            $t->longText('payload_text')->nullable();
            $t->string('state_transaction_id')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->text('response_notes')->nullable();
            $t->foreignId('prepared_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'state_code', 'status'], 'state_medicaid_sub_idx');
        });

        \DB::statement("
            ALTER TABLE emr_state_medicaid_submissions
            ADD CONSTRAINT state_medicaid_status_check
            CHECK (status IN ('staged_manual','pending','submitted','accepted','rejected','error'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_state_medicaid_submissions');
    }
};
