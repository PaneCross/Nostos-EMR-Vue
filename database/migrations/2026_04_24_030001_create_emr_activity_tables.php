<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_activity_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('site_id')->nullable()->constrained('shared_sites')->nullOnDelete();
            $t->string('title', 200);
            $t->string('category', 30);    // social|physical|cognitive|creative|spiritual|therapeutic
            $t->timestamp('scheduled_at');
            $t->integer('duration_min')->default(60);
            $t->string('location', 200)->nullable();
            $t->foreignId('facilitator_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('description')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'scheduled_at'], 'activity_events_schedule_idx');
        });

        Schema::create('emr_activity_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('activity_event_id')->constrained('emr_activity_events')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('attendance_status', 30);     // attended|declined|unable_due_to_illness|absent
            $t->string('engagement_level', 10)->nullable(); // low|med|high
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['activity_event_id', 'participant_id'], 'activity_attendance_uniq');
            $t->index(['tenant_id', 'participant_id'], 'activity_attendance_participant_idx');
        });

        DB::statement("ALTER TABLE emr_activity_events ADD CONSTRAINT activity_category_chk
            CHECK (category IN ('social','physical','cognitive','creative','spiritual','therapeutic'))");
        DB::statement("ALTER TABLE emr_activity_attendances ADD CONSTRAINT activity_status_chk
            CHECK (attendance_status IN ('attended','declined','unable_due_to_illness','absent'))");
        DB::statement("ALTER TABLE emr_activity_attendances ADD CONSTRAINT activity_engagement_chk
            CHECK (engagement_level IS NULL OR engagement_level IN ('low','med','high'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_activity_attendances');
        Schema::dropIfExists('emr_activity_events');
    }
};
