<?php

// ─── Migration: link training records to credentials (CEU credit) ────────────
// Many credentials require N hours of CEUs per renewal cycle (RN: 30h every
// 2y, MD: ~50h per state, MSW: 30-40h, etc.). When a training is logged,
// staff or admin can mark which credential it counts toward — the credential
// detail view then sums hours toward renewal.
//
// Nullable : not every training has to count toward a specific credential.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_staff_training_records', function (Blueprint $table) {
            $table->foreignId('credential_id')
                ->nullable()
                ->after('user_id')
                ->constrained('emr_staff_credentials')
                ->nullOnDelete();

            $table->index(['credential_id'], 'emr_staff_training_records_cred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_staff_training_records', function (Blueprint $table) {
            $table->dropForeign(['credential_id']);
            $table->dropIndex('emr_staff_training_records_cred_idx');
            $table->dropColumn('credential_id');
        });
    }
};
