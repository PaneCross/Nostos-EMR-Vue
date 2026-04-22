<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 13.1 (MVP roadmap) — Add RxNorm coding to allergies ──────────────
// Medications already carry rxnorm_code. Allergies need it too so drug-allergy
// cross-checks (the most important kind) can be structured rather than
// string-match-on-name. AllergyIntolerance.code in FHIR R4 accepts RxNorm.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_allergies', function (Blueprint $t) {
            $t->string('rxnorm_code', 20)->nullable()->after('allergen_name');
            $t->index(['tenant_id', 'rxnorm_code'], 'emr_allergies_tenant_rxnorm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_allergies', function (Blueprint $t) {
            $t->dropIndex('emr_allergies_tenant_rxnorm_idx');
            $t->dropColumn('rxnorm_code');
        });
    }
};
