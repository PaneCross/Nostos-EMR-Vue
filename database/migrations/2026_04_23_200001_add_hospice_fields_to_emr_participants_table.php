<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase C3 — Hospice workflow state on emr_participants ──────────────────
return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->string('hospice_status', 20)->nullable()->after('enrollment_status');
            $t->timestamp('hospice_started_at')->nullable()->after('hospice_status');
            $t->timestamp('hospice_last_idt_review_at')->nullable()->after('hospice_started_at');
            $t->string('hospice_provider_text', 200)->nullable()->after('hospice_last_idt_review_at');
            $t->text('hospice_diagnosis_text')->nullable()->after('hospice_provider_text');

            $t->index(['tenant_id', 'hospice_status'], 'emr_participants_hospice_idx');
        });

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_hospice_status_chk
            CHECK (hospice_status IS NULL OR hospice_status IN ('none','referred','enrolled','graduated','deceased'))");
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->dropIndex('emr_participants_hospice_idx');
            $t->dropColumn([
                'hospice_status',
                'hospice_started_at',
                'hospice_last_idt_review_at',
                'hospice_provider_text',
                'hospice_diagnosis_text',
            ]);
        });
    }
};
