<?php

// Phase S3 — Durable Medical Equipment (DME) tracking.
// PACE programs issue/service/return DME items (walkers, wheelchairs, hospital
// beds, oxygen concentrators, CPAPs, lift chairs, etc.) and pay for them out
// of capitation. We need a per-item ledger + per-issuance lifecycle so finance
// can audit unreturned/lost items and clinicians know what's deployed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_dme_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('item_type', 50);            // walker, wheelchair, hospital_bed, oxygen_concentrator, cpap, etc.
            $t->string('manufacturer', 100)->nullable();
            $t->string('model', 100)->nullable();
            $t->string('serial_number', 100)->nullable();
            $t->string('hcpcs_code', 10)->nullable();   // e.g. E0143 for folding walker
            $t->date('purchase_date')->nullable();
            $t->decimal('purchase_cost', 10, 2)->nullable();
            $t->string('status', 20);   // available | issued | servicing | retired | lost
            $t->date('next_service_due')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'item_type']);
        });

        DB::statement("ALTER TABLE emr_dme_items ADD CONSTRAINT dme_items_status_check
            CHECK (status IN ('available','issued','servicing','retired','lost'))");

        Schema::create('emr_dme_issuances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('dme_item_id');
            $t->unsignedBigInteger('participant_id');
            $t->date('issued_at');
            $t->unsignedBigInteger('issued_by_user_id');
            $t->date('expected_return_at')->nullable();
            $t->date('returned_at')->nullable();
            $t->unsignedBigInteger('returned_to_user_id')->nullable();
            $t->string('return_condition', 20)->nullable();   // good | damaged | lost
            $t->text('issue_notes')->nullable();
            $t->text('return_notes')->nullable();
            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $t->foreign('dme_item_id')->references('id')->on('emr_dme_items')->cascadeOnDelete();
            $t->foreign('participant_id')->references('id')->on('emr_participants')->cascadeOnDelete();
            $t->foreign('issued_by_user_id')->references('id')->on('shared_users')->cascadeOnDelete();
            $t->foreign('returned_to_user_id')->references('id')->on('shared_users')->nullOnDelete();
            $t->index(['tenant_id', 'participant_id', 'issued_at']);
        });

        DB::statement("ALTER TABLE emr_dme_issuances ADD CONSTRAINT dme_issuances_return_condition_check
            CHECK (return_condition IN ('good','damaged','lost') OR return_condition IS NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_dme_issuances');
        Schema::dropIfExists('emr_dme_items');
    }
};
