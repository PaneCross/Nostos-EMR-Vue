<?php

// ─── Phase P4 — HIPAA §164.404 / §164.408 Breach Notification ──────────────
// Tracks every confirmed (or suspected) breach of unsecured PHI from
// discovery through HHS notification, individual letters, and (≥500
// affected) media notice.
//
// Why: §164.404 requires notice to each affected individual within 60
// calendar days of discovery. §164.408 requires notice to HHS — annually
// by March 1 for breaches affecting <500, immediately for ≥500. This
// table holds the regulatory deadlines that BreachDeadlineJob enforces.
// affected_individuals_count gates the ≥500 media-notice path.
// CFR ref: 45 CFR §164.400–§164.414 (Breach Notification Rule).
// ────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_breach_incidents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->timestamp('discovered_at');
            $t->timestamp('occurred_at')->nullable();
            $t->unsignedInteger('affected_count')->default(0);
            $t->string('breach_type', 40); // lost_device | email_misdirect | unauthorized_access | hacking | paper_disposal | improper_disclosure | other
            $t->text('description');
            $t->text('root_cause')->nullable();
            $t->text('mitigation_taken')->nullable();
            $t->string('state', 2)->nullable(); // for media-notification rule (≥500 in same state)

            $t->timestamp('individual_notification_sent_at')->nullable();
            $t->timestamp('hhs_notified_at')->nullable();
            $t->timestamp('media_notified_at')->nullable();

            // §164.408 deadlines:
            //   500+ affected: HHS within 60 calendar days of discovery
            //   <500 affected: HHS by Mar 1 of year following the discovery
            $t->timestamp('hhs_deadline_at');

            $t->string('status', 30)->default('open'); // open | individuals_notified | hhs_notified | closed
            $t->unsignedBigInteger('logged_by_user_id')->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'discovered_at']);
            $t->index('hhs_deadline_at');
        });

        DB::statement("ALTER TABLE emr_breach_incidents ADD CONSTRAINT emr_breach_incidents_type_check CHECK (breach_type IN ('lost_device','email_misdirect','unauthorized_access','hacking','paper_disposal','improper_disclosure','other'))");
        DB::statement("ALTER TABLE emr_breach_incidents ADD CONSTRAINT emr_breach_incidents_status_check CHECK (status IN ('open','individuals_notified','hhs_notified','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_breach_incidents');
    }
};
