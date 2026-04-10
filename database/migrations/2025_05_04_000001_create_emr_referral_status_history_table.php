<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_referral_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')
                ->constrained('emr_referrals')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->string('from_status', 40)->nullable(); // null for initial 'new' creation entry
            $table->string('to_status', 40);
            $table->foreignId('transitioned_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — this table is append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_referral_status_history');
    }
};
