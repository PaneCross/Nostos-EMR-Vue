<?php

// ─── Phase P5 — X12 270/271 eligibility check log ─────────────────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_eligibility_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('participant_id');
            $t->string('payer_type', 20);   // medicare | medicaid | other
            $t->string('member_id_lookup', 60)->nullable();
            $t->timestamp('requested_at');
            $t->string('response_status', 20); // verified | inactive | denied | error | unverified
            $t->json('response_payload_json')->nullable();
            $t->string('gateway_used', 40)->default('null');
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'requested_at']);
        });

        DB::statement("ALTER TABLE emr_eligibility_checks ADD CONSTRAINT emr_eligibility_checks_payer_type_check CHECK (payer_type IN ('medicare','medicaid','other'))");
        DB::statement("ALTER TABLE emr_eligibility_checks ADD CONSTRAINT emr_eligibility_checks_response_check CHECK (response_status IN ('verified','inactive','denied','error','unverified'))");
    }
    public function down(): void { Schema::dropIfExists('emr_eligibility_checks'); }
};
