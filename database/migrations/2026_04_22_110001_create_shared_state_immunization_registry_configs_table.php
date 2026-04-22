<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 8 (MVP roadmap) — State Immunization Registry configs ─────────────
// Per-tenant, per-state IIS (Immunization Information System) submission
// configuration. Mirrors StateMedicaidConfig pattern. Governs how the EMR
// builds & tracks HL7 VXU outbound messages to state registries. Actual
// transmission is NOT wired (honest-labeled): we generate + queue + mark
// submitted. State Z-segment / profile quirks captured as free-text notes.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shared_state_immunization_registry_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('state_code', 2);
            $t->string('state_name');
            $t->string('registry_name')->nullable();      // e.g. "CAIR2", "NYSIIS", "Florida SHOTS"
            $t->string('submission_endpoint')->nullable(); // URL/SFTP path
            $t->string('auth_method', 40)->default('manual'); // manual|basic|oauth|sftp_key
            $t->string('profile_version', 20)->default('2.5.1'); // HL7 version
            $t->text('z_segment_notes')->nullable();       // state-specific companion guide quirks
            $t->string('sender_facility_id')->nullable(); // appears in MSH-4
            $t->string('sender_application')->nullable(); // appears in MSH-3
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['tenant_id', 'state_code'], 'state_iis_tenant_state_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_state_immunization_registry_configs');
    }
};
