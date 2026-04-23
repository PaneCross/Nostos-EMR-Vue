<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B7 — ClinicalNote ↔ Problem pivot ────────────────────────────────
// Many-to-many with one primary per note (enforced via partial unique index).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_clinical_note_problems', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinical_note_id')->constrained('emr_clinical_notes')->cascadeOnDelete();
            $t->foreignId('problem_id')->constrained('emr_problems')->cascadeOnDelete();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();

            $t->unique(['clinical_note_id', 'problem_id'], 'note_problem_uniq');
        });

        // Exactly one primary per note (when any). Partial unique index.
        DB::statement('CREATE UNIQUE INDEX note_primary_problem_uniq
            ON emr_clinical_note_problems (clinical_note_id)
            WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_clinical_note_problems');
    }
};
