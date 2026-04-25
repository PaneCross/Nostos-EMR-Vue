<?php

// Phase S2 — Contracted-provider network + per-contract rate table.
// PACE programs contract with hundreds of external specialists/SNFs/imaging
// centers/etc. Each contract has its own reimbursement rate per CPT code.
// Free-tier scope: structured network registry + flat per-CPT rate table.
// Real claims auto-adjudication = paywall (Wave V or later).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_contracted_providers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('name', 200);
            $t->string('npi', 10)->nullable();           // National Provider Identifier
            $t->string('tax_id', 20)->nullable();        // EIN
            $t->string('provider_type', 30);             // specialist|hospital|snf|imaging|lab|pharmacy|dme|other
            $t->string('specialty', 100)->nullable();    // free-text (cardiology, orthopedics, etc.)
            $t->string('phone', 30)->nullable();
            $t->string('fax', 30)->nullable();
            $t->string('address_line1', 200)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('state', 2)->nullable();
            $t->string('zip', 10)->nullable();
            $t->boolean('accepting_new_referrals')->default(true);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $t->index(['tenant_id', 'is_active']);
            $t->index(['tenant_id', 'provider_type']);
            $t->index('npi');
        });

        DB::statement("ALTER TABLE emr_contracted_providers ADD CONSTRAINT contracted_providers_type_check
            CHECK (provider_type IN ('specialist','hospital','snf','imaging','lab','pharmacy','dme','behavioral_health','other'))");

        Schema::create('emr_contracted_provider_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('contracted_provider_id');
            $t->string('contract_number', 60)->nullable();
            $t->date('effective_date');
            $t->date('termination_date')->nullable();
            $t->string('reimbursement_basis', 30); // fee_schedule|percent_of_medicare|percent_of_billed|flat_per_visit|capitation
            $t->decimal('reimbursement_value', 10, 4)->nullable(); // e.g. 80.0000 for 80% of Medicare
            $t->boolean('requires_prior_auth_default')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $t->foreign('contracted_provider_id')->references('id')->on('emr_contracted_providers')->cascadeOnDelete();
            $t->index(['tenant_id', 'effective_date']);
        });

        DB::statement("ALTER TABLE emr_contracted_provider_contracts ADD CONSTRAINT contracts_basis_check
            CHECK (reimbursement_basis IN ('fee_schedule','percent_of_medicare','percent_of_billed','flat_per_visit','capitation'))");

        Schema::create('emr_contracted_provider_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contract_id');
            $t->string('cpt_code', 10);
            $t->decimal('rate_amount', 10, 2);
            $t->string('modifier', 4)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('contract_id')->references('id')->on('emr_contracted_provider_contracts')->cascadeOnDelete();
            $t->unique(['contract_id', 'cpt_code', 'modifier'], 'contracts_cpt_uniq');
            $t->index('cpt_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_contracted_provider_rates');
        Schema::dropIfExists('emr_contracted_provider_contracts');
        Schema::dropIfExists('emr_contracted_providers');
    }
};
