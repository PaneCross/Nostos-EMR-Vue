<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B8b — Release of Information (ROI) requests ──────────────────────
// HIPAA §164.524 requires response within 30 days of a records request.
// due_by is auto-set to requested_at + 30 days at create time.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_roi_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('requestor_type', 20); // self|legal_rep|provider|attorney|insurer|other
            $t->string('requestor_name', 200);
            $t->string('requestor_contact', 300)->nullable(); // phone/email/address
            $t->text('records_requested_scope');
            $t->timestamp('requested_at');
            $t->timestamp('due_by');
            $t->string('status', 20)->default('pending'); // pending|in_progress|fulfilled|denied|withdrawn
            $t->timestamp('fulfilled_at')->nullable();
            $t->foreignId('fulfilled_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('denial_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'due_by'], 'roi_requests_status_idx');
        });

        DB::statement("
            ALTER TABLE emr_roi_requests
            ADD CONSTRAINT emr_roi_requests_type_check
            CHECK (requestor_type IN ('self','legal_rep','provider','attorney','insurer','other'))
        ");
        DB::statement("
            ALTER TABLE emr_roi_requests
            ADD CONSTRAINT emr_roi_requests_status_check
            CHECK (status IN ('pending','in_progress','fulfilled','denied','withdrawn'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_roi_requests');
    }
};
