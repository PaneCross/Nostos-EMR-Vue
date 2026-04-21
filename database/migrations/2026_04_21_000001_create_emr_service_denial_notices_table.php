<?php

// ─── Migration: Service Denial Notices ────────────────────────────────────────
// CMS-style denial letters for SDR denials and claim denials.
// 42 CFR §460.122 requires written notice of denial with:
//   - reason for denial
//   - appeal rights (standard 30-day / expedited 72-hour internal appeal)
//   - external/independent review path
//   - deadline to file appeal
// Append-only: once issued, the letter is evidence in CMS audits.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_service_denial_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            // Link to the thing being denied. Exactly one of these is set.
            $table->foreignId('sdr_id')->nullable()->constrained('emr_sdrs')->nullOnDelete();
            $table->foreignId('denial_record_id')->nullable()->constrained('emr_denial_records')->nullOnDelete();

            $table->string('reason_code', 80);
            $table->text('reason_narrative');

            $table->foreignId('issued_by_user_id')->constrained('shared_users')->restrictOnDelete();
            $table->timestampTz('issued_at');

            $table->string('delivery_method', 30); // mail | in_person | email | secure_portal | phone_documented
            $table->timestampTz('appeal_deadline_at'); // 30 days from issued_at for standard CMS rules
            $table->foreignId('pdf_document_id')->nullable()->constrained('emr_documents')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'participant_id']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['sdr_id']);
        });

        DB::statement("
            ALTER TABLE emr_service_denial_notices
            ADD CONSTRAINT emr_service_denial_notices_delivery_method_check
            CHECK (delivery_method IN ('mail', 'in_person', 'email', 'secure_portal', 'phone_documented'))
        ");

        // Append-only: block updates/deletes to the notice record itself.
        // (Linked pdf_document_id can be updated when the PDF is regenerated.)
        DB::statement('
            CREATE OR REPLACE RULE emr_service_denial_notices_no_delete AS
            ON DELETE TO emr_service_denial_notices DO INSTEAD NOTHING
        ');
    }

    public function down(): void
    {
        DB::statement('DROP RULE IF EXISTS emr_service_denial_notices_no_delete ON emr_service_denial_notices');
        Schema::dropIfExists('emr_service_denial_notices');
    }
};
