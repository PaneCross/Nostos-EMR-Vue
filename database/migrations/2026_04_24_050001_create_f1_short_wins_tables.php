<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Wound care photo attachments.
        Schema::create('emr_wound_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('wound_id')->constrained('emr_wound_records')->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained('emr_documents')->nullOnDelete();
            $t->timestamp('taken_at');
            $t->foreignId('taken_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'wound_id', 'taken_at'], 'wound_photos_wound_idx');
        });

        // Goals-of-care conversations (distinct from formal advance directive).
        Schema::create('emr_goals_of_care_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->date('conversation_date');
            $t->string('participants_present', 400)->nullable();
            $t->text('discussion_summary');
            $t->text('decisions_made')->nullable();
            $t->text('next_steps')->nullable();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'conversation_date'], 'gocc_trend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_goals_of_care_conversations');
        Schema::dropIfExists('emr_wound_photos');
    }
};
