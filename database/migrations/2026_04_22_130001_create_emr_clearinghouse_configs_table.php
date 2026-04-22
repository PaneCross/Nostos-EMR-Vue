<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 12 (MVP roadmap) — Clearinghouse configuration ───────────────────
// Per-tenant configuration for claims clearinghouse integration.
//
// The EMR currently ships with a NULL clearinghouse gateway by default —
// generated 837P batches are staged for MANUAL upload to whatever web portal
// the operator uses. When a real vendor contract lands (Availity, Change
// Healthcare, Office Ally, etc.), an IT admin toggles the adapter and fills
// in credentials; nothing else in the codebase changes.
//
// Credentials are encrypted at-rest via the model cast.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_clearinghouse_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('adapter', 40);      // null_gateway|availity|change_healthcare|office_ally
            $t->string('display_name');
            $t->string('submitter_id')->nullable();   // our assigned submitter ID
            $t->string('receiver_id')->nullable();    // clearinghouse receiver ID
            $t->string('endpoint_url')->nullable();   // REST / SFTP host
            $t->text('credentials_json')->nullable(); // ENCRYPTED — API key / OAuth tokens / SFTP creds
            $t->string('environment', 20)->default('sandbox'); // sandbox|production
            $t->integer('submission_timeout_s')->default(60);
            $t->integer('max_retries')->default(3);
            $t->integer('retry_backoff_s')->default(30);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamp('last_successful_at')->nullable();
            $t->timestamp('last_failed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'is_active'], 'clearinghouse_tenant_active_idx');
        });

        \DB::statement("
            ALTER TABLE emr_clearinghouse_configs
            ADD CONSTRAINT clearinghouse_adapter_check
            CHECK (adapter IN ('null_gateway','availity','change_healthcare','office_ally','custom'))
        ");
        \DB::statement("
            ALTER TABLE emr_clearinghouse_configs
            ADD CONSTRAINT clearinghouse_env_check
            CHECK (environment IN ('sandbox','production'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_clearinghouse_configs');
    }
};
