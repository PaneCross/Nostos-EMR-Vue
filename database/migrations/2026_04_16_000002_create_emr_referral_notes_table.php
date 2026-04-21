<?php

// ─── Migration: emr_referral_notes ────────────────────────────────────────────
// Append-only thread of notes on enrollment referrals. Each row is user-attributed
// and timestamped; once written, records are immutable (no updated_at). Persists
// across all referral status transitions through enrollment or decline/withdraw.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_referral_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('emr_referrals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('shared_users');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
            // NO updated_at — notes are immutable append-only per HIPAA documentation standard

            $table->index(['referral_id', 'created_at'], 'emr_referral_notes_thread_idx');
            $table->index(['tenant_id', 'created_at'], 'emr_referral_notes_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_referral_notes');
    }
};
