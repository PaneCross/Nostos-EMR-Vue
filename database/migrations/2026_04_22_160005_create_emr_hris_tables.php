<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.7 — HRIS webhook scaffolding ──────────────────────────────────
// Per-tenant HRIS provider config + append-only log of inbound webhook
// events. Same scaffold-first pattern as the clearinghouse gateway (Phase 12):
// the NullGateway-equivalent here is "accepts webhook, stores event row,
// writes audit log" — does NOT actually sync anything into the credential
// system until a vendor contract is signed and the adapter body is wired.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_hris_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('provider', 40);     // bamboohr|rippling|gusto|custom
            $t->string('webhook_secret_hash', 64)->nullable(); // shared secret for HMAC verify
            $t->text('credentials_json')->nullable(); // ENCRYPTED — API key for outbound sync
            $t->boolean('is_active')->default(false);
            $t->timestamp('last_event_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'provider'], 'hris_configs_tenant_provider_uq');
        });

        Schema::create('emr_hris_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('hris_config_id')->nullable()->constrained('emr_hris_configs')->nullOnDelete();
            $t->string('provider', 40);
            $t->string('event_type', 60); // credential_added|credential_expired|employee_terminated|etc.
            $t->jsonb('payload');
            $t->string('processing_status', 20)->default('received'); // received|staged|committed|ignored|failed
            $t->text('processing_notes')->nullable();
            $t->timestamp('received_at');
            $t->timestamps();

            $t->index(['tenant_id', 'processing_status'], 'hris_events_tenant_status_idx');
        });

        \DB::statement("
            ALTER TABLE emr_hris_configs
            ADD CONSTRAINT hris_provider_check
            CHECK (provider IN ('bamboohr','rippling','gusto','custom'))
        ");
        \DB::statement("
            ALTER TABLE emr_hris_events
            ADD CONSTRAINT hris_event_status_check
            CHECK (processing_status IN ('received','staged','committed','ignored','failed'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_hris_events');
        Schema::dropIfExists('emr_hris_configs');
    }
};
