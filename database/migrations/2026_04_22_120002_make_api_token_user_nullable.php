<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 11 — allow system-issued tokens (client_credentials grant) ────────
// client_credentials is a backend-to-backend flow with no human user. We
// need `user_id` nullable on emr_api_tokens so the SmartOAuthController can
// mint system tokens without fabricating a synthetic user.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_api_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('emr_api_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
