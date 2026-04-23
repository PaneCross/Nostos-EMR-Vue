<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.8 — Governing board / committee management ───────────────────
// Committees (QAPI, IDT Oversight, Formulary, Governing Board), their
// members, scheduled meetings, and lightweight votes.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_committees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('committee_type', 40); // qapi|idt_oversight|formulary|governing_board|custom
            $t->text('charter')->nullable();
            $t->string('meeting_cadence', 40)->nullable(); // monthly|quarterly|as_needed
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['tenant_id', 'name'], 'committees_tenant_name_uq');
        });

        Schema::create('emr_committee_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('committee_id')->constrained('emr_committees')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('external_name', 150)->nullable(); // for non-user members (community reps, etc.)
            $t->string('role', 60); // chair|vice_chair|secretary|member
            $t->date('term_start')->nullable();
            $t->date('term_end')->nullable();
            $t->boolean('voting_member')->default(true);
            $t->timestamps();
        });

        Schema::create('emr_committee_meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('committee_id')->constrained('emr_committees')->cascadeOnDelete();
            $t->date('scheduled_date');
            $t->string('location', 150)->nullable();
            $t->string('status', 20)->default('scheduled'); // scheduled|held|cancelled
            $t->text('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->jsonb('attendees_json')->nullable(); // array of {user_id or external_name}
            $t->timestamp('held_at')->nullable();
            $t->timestamps();

            $t->index(['committee_id', 'scheduled_date'], 'meetings_committee_date_idx');
        });

        Schema::create('emr_committee_votes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('emr_committee_meetings')->cascadeOnDelete();
            $t->string('motion_text', 500);
            $t->integer('votes_yes')->default(0);
            $t->integer('votes_no')->default(0);
            $t->integer('votes_abstain')->default(0);
            $t->string('outcome', 20)->default('pending'); // passed|failed|tabled|pending
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        \DB::statement("
            ALTER TABLE emr_committees
            ADD CONSTRAINT committees_type_check
            CHECK (committee_type IN ('qapi','idt_oversight','formulary','governing_board','custom'))
        ");
        \DB::statement("
            ALTER TABLE emr_committee_meetings
            ADD CONSTRAINT meetings_status_check
            CHECK (status IN ('scheduled','held','cancelled'))
        ");
        \DB::statement("
            ALTER TABLE emr_committee_votes
            ADD CONSTRAINT votes_outcome_check
            CHECK (outcome IN ('passed','failed','tabled','pending'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_committee_votes');
        Schema::dropIfExists('emr_committee_meetings');
        Schema::dropIfExists('emr_committee_members');
        Schema::dropIfExists('emr_committees');
    }
};
