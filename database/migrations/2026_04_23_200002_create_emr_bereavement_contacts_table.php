<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase C3 — Bereavement contact schedule ────────────────────────────────
return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_bereavement_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('contact_type', 20);   // day_15|day_30|month_3
            $t->string('family_contact_name', 200)->nullable();
            $t->string('family_contact_phone', 50)->nullable();
            $t->timestamp('scheduled_at');
            $t->string('status', 20)->default('scheduled'); // scheduled|completed|missed|declined
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'scheduled_at'], 'bereavement_queue_idx');
        });

        DB::statement("ALTER TABLE emr_bereavement_contacts ADD CONSTRAINT emr_bereavement_type_chk
            CHECK (contact_type IN ('day_15','day_30','month_3'))");
        DB::statement("ALTER TABLE emr_bereavement_contacts ADD CONSTRAINT emr_bereavement_status_chk
            CHECK (status IN ('scheduled','completed','missed','declined'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_bereavement_contacts');
    }
};
