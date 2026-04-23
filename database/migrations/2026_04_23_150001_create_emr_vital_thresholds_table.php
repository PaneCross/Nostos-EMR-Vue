<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B6 — Vital thresholds (per-tenant override of defaults) ──────────
// Empty = use hard-coded defaults in VitalThreshold::DEFAULTS.
// Populated = tenant-specific override for that vital field.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_vital_thresholds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('vital_field', 40);  // bp_systolic|bp_diastolic|pulse|respiratory_rate|temperature_f|o2_saturation|blood_glucose
            $t->decimal('warning_low', 8, 2)->nullable();
            $t->decimal('warning_high', 8, 2)->nullable();
            $t->decimal('critical_low', 8, 2)->nullable();
            $t->decimal('critical_high', 8, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'vital_field'], 'vital_thresholds_tenant_field_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_vital_thresholds');
    }
};
