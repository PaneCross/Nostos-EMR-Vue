<?php

// ─── Phase P6 — Internal prior-authorization request workflow ──────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_prior_auth_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('participant_id');
            $t->string('related_to_type', 60); // medication | clinical_order | procedure
            $t->unsignedBigInteger('related_to_id');
            $t->string('payer_type', 30); // medicare_d | medicaid | other
            $t->text('justification_text');
            $t->string('urgency', 20)->default('standard'); // standard | expedited
            $t->string('status', 20)->default('draft'); // draft|submitted|approved|denied|withdrawn|expired
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decision_at')->nullable();
            $t->text('decision_rationale')->nullable();
            $t->date('expiration_date')->nullable();
            $t->string('approval_reference', 100)->nullable();
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'participant_id']);
            $t->index(['related_to_type', 'related_to_id']);
        });

        DB::statement("ALTER TABLE emr_prior_auth_requests ADD CONSTRAINT pa_status_check CHECK (status IN ('draft','submitted','approved','denied','withdrawn','expired'))");
        DB::statement("ALTER TABLE emr_prior_auth_requests ADD CONSTRAINT pa_urgency_check CHECK (urgency IN ('standard','expedited'))");
        DB::statement("ALTER TABLE emr_prior_auth_requests ADD CONSTRAINT pa_payer_check CHECK (payer_type IN ('medicare_d','medicaid','other'))");
    }
    public function down(): void { Schema::dropIfExists('emr_prior_auth_requests'); }
};
