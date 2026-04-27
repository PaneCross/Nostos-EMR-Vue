<?php

// ─── 2026_04_26 — site_id override on emr_notification_preferences ────────────
// Phase OS2 — adds per-site override support on top of the existing org-level
// notification preference rows.
//
// Cascade rule (encoded in NotificationPreferenceService::shouldNotify):
//   Site-level override (site_id = X)  beats
//   Org-level default    (site_id = NULL) beats
//   Catalog default
//
// Schema change:
//   - Adds nullable site_id column (NULL = org-level row).
//   - Replaces unique(tenant_id, preference_key) with
//     unique(tenant_id, site_id, preference_key). Postgres treats NULL as
//     distinct in unique indexes, so two rows with same tenant_id +
//     preference_key but different site_id (one NULL, one set) coexist.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_notification_preferences', function (Blueprint $t) {
            $t->unsignedBigInteger('site_id')->nullable()->after('tenant_id');

            // Drop the old uniqueness constraint and add the wider one.
            $t->dropUnique(['tenant_id', 'preference_key']);
            $t->unique(['tenant_id', 'site_id', 'preference_key'],
                'emr_notif_prefs_tenant_site_key_unique');

            $t->foreign('site_id')
                ->references('id')->on('shared_sites')
                ->cascadeOnDelete();

            $t->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('emr_notification_preferences', function (Blueprint $t) {
            $t->dropForeign(['site_id']);
            $t->dropIndex(['site_id']);
            $t->dropUnique('emr_notif_prefs_tenant_site_key_unique');
            $t->unique(['tenant_id', 'preference_key']);
            $t->dropColumn('site_id');
        });
    }
};
