<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_participant_portal_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            // Proxy: NULL = participant themselves; set = representative on a ParticipantContact.
            $t->foreignId('participant_contact_id')->nullable();
            $t->string('proxy_scope', 20)->nullable(); // full|limited (null = participant themselves)
            $t->string('email', 200);
            $t->string('phone', 30)->nullable();
            $t->string('password', 255)->nullable(); // bcrypt; OTP is primary but fallback
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->string('portal_consent_record_id')->nullable(); // links to a signed ConsentRecord
            $t->timestamps();

            $t->unique(['tenant_id', 'email'], 'portal_users_email_uniq');
            $t->index(['tenant_id', 'participant_id'], 'portal_users_participant_idx');
        });

        DB::statement("ALTER TABLE emr_participant_portal_users ADD CONSTRAINT portal_users_proxy_scope_chk
            CHECK (proxy_scope IS NULL OR proxy_scope IN ('full','limited'))");

        // Secure messaging between portal-user and staff.
        Schema::create('emr_portal_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            // Exactly one of from_portal_user_id / from_staff_user_id is populated.
            $t->foreignId('from_portal_user_id')->nullable();
            $t->foreignId('from_staff_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('subject', 200);
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'created_at'], 'portal_msgs_convo_idx');
        });

        // Portal-originated requests (records / appointment / contact-update).
        Schema::create('emr_portal_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('from_portal_user_id')->nullable();
            $t->string('request_type', 30);   // records|appointment|contact_update
            $t->jsonb('payload')->nullable();
            $t->string('status', 20)->default('pending'); // pending|processed|rejected
            $t->foreignId('processed_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamp('processed_at')->nullable();
            $t->text('staff_note')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status'], 'portal_reqs_queue_idx');
        });

        DB::statement("ALTER TABLE emr_portal_requests ADD CONSTRAINT portal_requests_type_chk
            CHECK (request_type IN ('records','appointment','contact_update'))");
        DB::statement("ALTER TABLE emr_portal_requests ADD CONSTRAINT portal_requests_status_chk
            CHECK (status IN ('pending','processed','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_portal_requests');
        Schema::dropIfExists('emr_portal_messages');
        Schema::dropIfExists('emr_participant_portal_users');
    }
};
