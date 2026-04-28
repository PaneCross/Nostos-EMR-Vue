<?php

// ─── Migration: add job_title + supervisor_user_id to shared_users ────────────
// Two new fields to support credential targeting and notification escalation:
//
//  - job_title  : nullable string referencing emr_job_titles.code. Org defines
//                 its own controlled vocab via Org Settings → Job Titles.
//                 Used by credential definitions to target specific licensed
//                 roles (e.g. RN License targets job_title='rn').
//
//  - supervisor_user_id : nullable self-FK. Used by CredentialExpirationAlertJob
//                 at the 14-day mark to CC the staff member's direct supervisor.
//                 Future-proof for other supervisor-routed flows.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            $table->string('job_title', 60)->nullable()->after('role');
            $table->foreignId('supervisor_user_id')
                ->nullable()
                ->after('job_title')
                ->constrained('shared_users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'job_title']);
        });
    }

    public function down(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            $table->dropForeign(['supervisor_user_id']);
            $table->dropIndex(['tenant_id', 'job_title']);
            $table->dropColumn(['job_title', 'supervisor_user_id']);
        });
    }
};
