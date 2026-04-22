<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 13.1 (MVP roadmap) — Add SNOMED coding to problem list ───────────
// ICD-10 remains the primary billing code; SNOMED CT is the preferred clinical
// code for interoperability. Both are stored so either can be searched,
// displayed, or sent over FHIR (Condition.code accepts multiple codings).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_problems', function (Blueprint $t) {
            $t->string('snomed_code', 20)->nullable()->after('icd10_description');
            $t->string('snomed_display')->nullable()->after('snomed_code');
            $t->index(['tenant_id', 'snomed_code'], 'emr_problems_tenant_snomed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_problems', function (Blueprint $t) {
            $t->dropIndex('emr_problems_tenant_snomed_idx');
            $t->dropColumn(['snomed_code', 'snomed_display']);
        });
    }
};
