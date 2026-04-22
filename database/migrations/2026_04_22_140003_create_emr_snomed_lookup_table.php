<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 13.1 (MVP roadmap) — SNOMED CT + RxNorm lookup tables ────────────
// Small seed-based lookup tables for the common codes encountered in a PACE
// participant population (cardiovascular, diabetes, dementia, fall-risk,
// common allergens, core PACE medications). NOT a full SNOMED/RxNorm
// distribution — licensing + size make that untenable for a demo build.
//
// Both tables are shared (tenant-agnostic) reference data. Populated by
// Phase13CodingSeeder.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shared_snomed_lookup', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('display', 300);
            $t->string('category', 60)->nullable(); // condition|finding|disorder|procedure
            $t->string('icd10_code', 10)->nullable(); // convenience cross-walk
            $t->timestamps();
            $t->index('category');
        });

        Schema::create('shared_rxnorm_lookup', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('display', 300);
            $t->string('tty', 10)->nullable(); // term type: SCD, SBD, IN, BN, etc.
            $t->boolean('is_allergen_candidate')->default(false); // helpful for allergy picker
            $t->timestamps();
            $t->index('is_allergen_candidate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_rxnorm_lookup');
        Schema::dropIfExists('shared_snomed_lookup');
    }
};
