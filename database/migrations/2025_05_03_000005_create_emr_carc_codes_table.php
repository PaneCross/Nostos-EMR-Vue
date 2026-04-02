<?php

// Migration 106 — emr_carc_codes
//
// Lookup table for X12 Claim Adjustment Reason Codes (CARCs).
// CARCs are standardized codes used in X12 835 CAS segments to explain why
// a payer reduced, denied, or adjusted a claim from the submitted amount.
//
// Published by Washington Publishing Company (WPC) under contract with CMS.
// Version used: 2024 CARC code set (current as of CMS update 2024-Q3).
//
// Populated by CarcCodeSeeder with 50+ commonly encountered codes in PACE billing.
// is_denial_indicator = true when a CO-group adjustment with this code means the
// claim was denied (not merely contractually adjusted).
//
// Append-only (UPDATED_AT = null) — reference data; never mutated.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_carc_codes', function (Blueprint $table) {
            $table->id();

            // CARC code value (e.g., '1', '45', '97', 'MA01')
            $table->string('code', 10)->unique();

            // Human-readable description of the adjustment reason
            $table->string('description');

            // Plain-language explanation for billing staff
            $table->text('notes')->nullable();

            // Whether this code in a CO group indicates a true denial
            // vs. a contractual write-off that is expected and non-actionable
            $table->boolean('is_denial_indicator')->default(false);

            // Category grouping for denial management classification
            // Maps to emr_denial_records.denial_category
            $table->string('denial_category', 30)->nullable();

            // Whether this code is still active in the current CMS CARC code set
            $table->boolean('is_active')->default(true);

            // Append-only reference data — no updated_at
            $table->timestamp('created_at')->nullable();
        });

        // Index for fast code lookups during 835 parsing
        \Illuminate\Support\Facades\DB::statement('CREATE INDEX emr_carc_codes_code_active_idx ON emr_carc_codes (code, is_active)');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_carc_codes');
    }
};
