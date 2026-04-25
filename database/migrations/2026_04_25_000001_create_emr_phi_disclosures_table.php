<?php

// ─── Phase P2 — HIPAA §164.528 Accounting of Disclosures ────────────────────
// Distinct from the access-side audit log. Tracks every PHI release to a
// third party so the EMR can produce, on patient request, a list of
// disclosures going back 6 years.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_phi_disclosures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('participant_id');
            $t->timestamp('disclosed_at');
            $t->unsignedBigInteger('disclosed_by_user_id')->nullable();

            $t->string('recipient_type', 30); // insurer | public_health | lab | family | legal | patient_self | provider | other
            $t->string('recipient_name', 200);
            $t->string('recipient_contact', 200)->nullable();
            $t->text('disclosure_purpose'); // e.g. "ROI request from John Doe (son, POA)"
            $t->string('disclosure_method', 20); // paper | fax | email | portal | api | hie

            $t->text('records_described'); // free-text summary of what was sent
            // Polymorphic — usually RoiRequest, EhiExport, ConsentRecord, etc.
            $t->string('related_to_type', 60)->nullable();
            $t->unsignedBigInteger('related_to_id')->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'disclosed_at']);
            $t->index(['tenant_id', 'recipient_type']);
            $t->index(['related_to_type', 'related_to_id']);
        });

        DB::statement("ALTER TABLE emr_phi_disclosures ADD CONSTRAINT emr_phi_disclosures_recipient_type_check CHECK (recipient_type IN ('insurer','public_health','lab','family','legal','patient_self','provider','other'))");
        DB::statement("ALTER TABLE emr_phi_disclosures ADD CONSTRAINT emr_phi_disclosures_method_check CHECK (disclosure_method IN ('paper','fax','email','portal','api','hie'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_phi_disclosures');
    }
};
