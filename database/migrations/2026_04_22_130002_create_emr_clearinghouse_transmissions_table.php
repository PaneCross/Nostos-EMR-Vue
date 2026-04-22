<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 12 (MVP roadmap) — Clearinghouse transmission log ────────────────
// Append-only wire-level audit of every outbound claim batch transmission
// and every inbound acknowledgment received from the clearinghouse. One row
// per HTTP / SFTP exchange. Persists vendor-specific tracking IDs so we can
// poll status later.
//
// Purpose: honest accounting of "what we sent and what came back," even when
// the NullGateway stages files for manual upload (status=staged_manual).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_clearinghouse_transmissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('edi_batch_id')->constrained('emr_edi_batches')->cascadeOnDelete();
            $t->foreignId('config_id')->nullable()->constrained('emr_clearinghouse_configs')->nullOnDelete();
            $t->string('adapter', 40);
            $t->string('direction', 10);             // outbound|inbound
            $t->string('transaction_kind', 20);      // 837P|277CA|999|835|status_poll
            $t->string('vendor_transaction_id')->nullable();
            $t->string('status', 30);                // staged_manual|pending|submitted|accepted|rejected|timeout|error
            $t->timestamp('attempted_at');
            $t->timestamp('completed_at')->nullable();
            $t->integer('attempt_number')->default(1);
            $t->text('raw_payload')->nullable();     // the actual EDI/text (trimmed in UI)
            $t->text('error_message')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'edi_batch_id'], 'ch_trans_tenant_batch_idx');
            $t->index(['status', 'attempted_at'], 'ch_trans_status_idx');
        });

        \DB::statement("
            ALTER TABLE emr_clearinghouse_transmissions
            ADD CONSTRAINT ch_trans_direction_check
            CHECK (direction IN ('outbound','inbound'))
        ");
        \DB::statement("
            ALTER TABLE emr_clearinghouse_transmissions
            ADD CONSTRAINT ch_trans_status_check
            CHECK (status IN ('staged_manual','pending','submitted','accepted','rejected','timeout','error'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_clearinghouse_transmissions');
    }
};
