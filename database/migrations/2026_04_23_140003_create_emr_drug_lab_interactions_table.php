<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B5 — Drug-lab interaction reference table ────────────────────────
// Reference (non-tenant) data: for each common drug + required monitoring
// lab, the recommended frequency + the critical thresholds. Seeded via
// DrugLabInteractionSeeder. Used by clinicians (CDS surface) and by
// MedicationController on prescribe to schedule monitoring labs.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_drug_lab_interactions', function (Blueprint $t) {
            $t->id();
            $t->string('drug_keyword', 80);          // matched against Medication.drug_name ILIKE
            $t->string('lab_name', 80);              // e.g. "INR", "Lithium Level"
            $t->string('loinc_code', 20)->nullable();
            $t->integer('monitoring_frequency_days')->nullable();
            $t->decimal('critical_low', 10, 3)->nullable();
            $t->decimal('critical_high', 10, 3)->nullable();
            $t->string('units', 20)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['drug_keyword', 'lab_name'], 'drug_lab_pair_uniq');
            $t->index('drug_keyword');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_drug_lab_interactions');
    }
};
