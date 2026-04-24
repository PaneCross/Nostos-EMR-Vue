<?php

// ─── Phase O1 — extend emr_participants.advance_directive_type CHECK ────────
// Adds 'molst' (common NY/MA alternative to POLST). The M1 wizard listed it
// as a UI option but had to merge into 'polst' because the CHECK rejected it.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_participants DROP CONSTRAINT IF EXISTS emr_participants_advance_directive_type_check');
        DB::statement("
            ALTER TABLE emr_participants
            ADD CONSTRAINT emr_participants_advance_directive_type_check
            CHECK (advance_directive_type IS NULL OR advance_directive_type IN (
                'dnr','polst','molst','living_will','healthcare_proxy','combined'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_participants DROP CONSTRAINT IF EXISTS emr_participants_advance_directive_type_check');
        DB::statement("
            ALTER TABLE emr_participants
            ADD CONSTRAINT emr_participants_advance_directive_type_check
            CHECK (advance_directive_type IS NULL OR advance_directive_type IN (
                'dnr','polst','living_will','healthcare_proxy','combined'
            ))
        ");
    }
};
