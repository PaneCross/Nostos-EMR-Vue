<?php

// ─── Migration: Appeal Events (append-only audit) ─────────────────────────────
// Every status change, acknowledgment, decision, letter issuance, or external
// review action recorded as an immutable event. Produces the full timeline
// that CMS audits require (§460.122 audit universe).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_appeal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('appeal_id')->constrained('emr_appeals')->cascadeOnDelete();

            $table->string('event_type', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('narrative')->nullable();
            $table->json('metadata')->nullable(); // letter doc ids, attachments, etc.

            $table->foreignId('actor_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->timestampTz('occurred_at');

            $table->timestampTz('created_at')->useCurrent();
            // NO updated_at — append-only.

            $table->index(['tenant_id', 'appeal_id', 'occurred_at']);
        });

        DB::statement('
            CREATE OR REPLACE RULE emr_appeal_events_no_update AS
            ON UPDATE TO emr_appeal_events DO INSTEAD NOTHING
        ');

        DB::statement('
            CREATE OR REPLACE RULE emr_appeal_events_no_delete AS
            ON DELETE TO emr_appeal_events DO INSTEAD NOTHING
        ');
    }

    public function down(): void
    {
        DB::statement('DROP RULE IF EXISTS emr_appeal_events_no_update ON emr_appeal_events');
        DB::statement('DROP RULE IF EXISTS emr_appeal_events_no_delete ON emr_appeal_events');
        Schema::dropIfExists('emr_appeal_events');
    }
};
