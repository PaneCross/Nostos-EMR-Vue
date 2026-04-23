<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B3 — Sentinel event classification on existing incidents ─────────
// Adds sentinel classification + deadlines to emr_incidents. Keeping a single
// table (no new sentinel_events table) because sentinels are a subset of
// incidents with additional tracking, not a fundamentally different entity.
//
// When classified as a sentinel:
//   - cms_5day_deadline = sentinel_classified_at + 5 days   (CMS notification)
//   - rca_30day_deadline = sentinel_classified_at + 30 days (RCA completion)
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_incidents', function (Blueprint $t) {
            $t->boolean('is_sentinel')->default(false)->after('rca_completed');
            $t->timestamp('sentinel_classified_at')->nullable()->after('is_sentinel');
            $t->foreignId('sentinel_classified_by_user_id')->nullable()
                ->after('sentinel_classified_at')
                ->constrained('shared_users')->nullOnDelete();
            $t->text('sentinel_classification_reason')->nullable()
                ->after('sentinel_classified_by_user_id');

            // Dual deadlines specific to sentinel handling
            $t->timestamp('sentinel_cms_5day_deadline')->nullable()->after('sentinel_classification_reason');
            $t->timestamp('sentinel_rca_30day_deadline')->nullable()->after('sentinel_cms_5day_deadline');
            $t->timestamp('rca_completed_at')->nullable()->after('sentinel_rca_30day_deadline');

            $t->index(['tenant_id', 'is_sentinel'], 'incidents_sentinel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_incidents', function (Blueprint $t) {
            $t->dropIndex('incidents_sentinel_idx');
            $t->dropConstrainedForeignId('sentinel_classified_by_user_id');
            $t->dropColumn([
                'is_sentinel',
                'sentinel_classified_at',
                'sentinel_classification_reason',
                'sentinel_cms_5day_deadline',
                'sentinel_rca_30day_deadline',
                'rca_completed_at',
            ]);
        });
    }
};
