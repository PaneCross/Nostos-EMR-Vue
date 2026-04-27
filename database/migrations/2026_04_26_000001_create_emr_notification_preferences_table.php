<?php

// ─── 2026_04_26 — emr_notification_preferences ────────────────────────────────
// Per-tenant on/off control for OPTIONAL notification + workflow routing.
// One row per (tenant_id, preference_key). CMS-required notification paths
// stay hardwired in code; this table governs the optional + reserved keys
// surfaced on the Site Settings page (/executive/site-settings).
//
// Why row-per-key vs JSON blob: queryable + indexable + new keys ship without
// schema migrations + the IT-admin audit log can record per-key flips.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('preference_key', 100); // e.g. designation.nursing_director.fall_risk
            $t->boolean('enabled')->default(false);
            // `value` is reserved for non-boolean prefs (numeric warn-days, multi-value
            // recipient choice). Most v1 keys are pure booleans and ignore this.
            $t->json('value')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'preference_key']);
            $t->index('tenant_id');

            $t->foreign('tenant_id')
                ->references('id')->on('shared_tenants')
                ->cascadeOnDelete();
            $t->foreign('updated_by_user_id')
                ->references('id')->on('shared_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_notification_preferences');
    }
};
