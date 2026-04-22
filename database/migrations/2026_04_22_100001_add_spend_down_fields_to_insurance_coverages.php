<?php

// ─── Migration: Medicaid spend-down / share-of-cost fields ───────────────────
// Many dual-eligible PACE participants have a Medicaid "spend-down" obligation
// — a monthly amount they must contribute before Medicaid coverage activates.
// States vary (CA "Medi-Cal share-of-cost", NY "surplus", FL "spend-down").
//
// Per-coverage fields (Medicaid rows only):
//   share_of_cost_monthly_amount — dollar amount due each month
//   spend_down_threshold         — some states use a threshold model (accumulate
//                                   expenses until threshold reached)
//   spend_down_period_start       — typically month-long but some states use
//   spend_down_period_end          6-month or fiscal period
//   spend_down_state             — 2-letter state code driving rule set
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_insurance_coverages', function (Blueprint $table) {
            $table->decimal('share_of_cost_monthly_amount', 12, 2)->nullable()->after('is_active');
            $table->decimal('spend_down_threshold', 12, 2)->nullable()->after('share_of_cost_monthly_amount');
            $table->date('spend_down_period_start')->nullable()->after('spend_down_threshold');
            $table->date('spend_down_period_end')->nullable()->after('spend_down_period_start');
            $table->string('spend_down_state', 2)->nullable()->after('spend_down_period_end');

            $table->index(['participant_id', 'payer_type', 'is_active'], 'emr_insurance_medicaid_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_insurance_coverages', function (Blueprint $table) {
            $table->dropIndex('emr_insurance_medicaid_active_idx');
            $table->dropColumn([
                'share_of_cost_monthly_amount',
                'spend_down_threshold',
                'spend_down_period_start',
                'spend_down_period_end',
                'spend_down_state',
            ]);
        });
    }
};
