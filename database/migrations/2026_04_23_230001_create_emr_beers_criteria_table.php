<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Reference (non-tenant) table — AGS Beers Criteria 2023 update.
        Schema::create('emr_beers_criteria', function (Blueprint $t) {
            $t->id();
            $t->string('drug_keyword', 120);      // matched via ILIKE
            $t->string('risk_category', 60);      // e.g. "anticholinergic", "benzodiazepine", "PPI long-term"
            $t->text('rationale');
            $t->string('recommendation', 60);     // avoid | use_with_caution | dose_adjust_by_renal_function
            $t->string('evidence_quality', 20)->nullable(); // strong|moderate|weak
            $t->timestamps();

            $t->index('drug_keyword');
        });

        // Pharmacist polypharmacy review queue.
        Schema::create('emr_polypharmacy_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->integer('active_med_count_at_queue');
            $t->timestamp('queued_at');
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('deprescribing_recommendations')->nullable();
            $t->jsonb('pim_flags_at_review')->nullable(); // snapshot of Beers flags at review time
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'participant_id', 'queued_at'], 'polypharm_review_uniq');
            $t->index(['tenant_id', 'reviewed_at'], 'polypharm_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_polypharmacy_reviews');
        Schema::dropIfExists('emr_beers_criteria');
    }
};
