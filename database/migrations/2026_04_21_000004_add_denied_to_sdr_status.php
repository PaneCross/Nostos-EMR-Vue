<?php

// ─── Migration: Add 'denied' status to SDRs ───────────────────────────────────
// Required for §460.122 denial → appeal workflow: an SDR can be denied
// (with narrative reason), which triggers issuance of a denial notice and
// establishes the participant's right to appeal.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_sdrs DROP CONSTRAINT IF EXISTS emr_sdrs_status_check');
        DB::statement("
            ALTER TABLE emr_sdrs
            ADD CONSTRAINT emr_sdrs_status_check
            CHECK (status IN ('submitted', 'acknowledged', 'in_progress', 'completed', 'cancelled', 'denied'))
        ");
    }

    public function down(): void
    {
        // Revert to old set. Participants already in denied state would be rejected;
        // caller must migrate data first.
        DB::statement('ALTER TABLE emr_sdrs DROP CONSTRAINT IF EXISTS emr_sdrs_status_check');
        DB::statement("
            ALTER TABLE emr_sdrs
            ADD CONSTRAINT emr_sdrs_status_check
            CHECK (status IN ('submitted', 'acknowledged', 'in_progress', 'completed', 'cancelled'))
        ");
    }
};
