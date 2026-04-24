<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B8a — E-signature fields on emr_consent_records ──────────────────
// ESIGN / UETA require capture of signer intent + identity + audit trail.
// signature_image_blob is base64 PNG (encrypted at rest via model cast).
// Proxy fields capture legal-representative flow when participant can't sign.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_consent_records', function (Blueprint $t) {
            $t->text('signature_image_blob')->nullable()->after('representative_type');
            $t->boolean('signed_by_participant')->default(false)->after('signature_image_blob');
            $t->string('proxy_signer_name', 200)->nullable()->after('signed_by_participant');
            $t->string('proxy_relationship', 100)->nullable()->after('proxy_signer_name');
            $t->string('signed_ip_address', 45)->nullable()->after('proxy_relationship');
            $t->string('esign_disclaimer_version', 20)->nullable()->after('signed_ip_address');
            $t->timestamp('signed_at')->nullable()->after('esign_disclaimer_version');
        });
    }

    public function down(): void
    {
        Schema::table('emr_consent_records', function (Blueprint $t) {
            $t->dropColumn([
                'signature_image_blob',
                'signed_by_participant',
                'proxy_signer_name',
                'proxy_relationship',
                'signed_ip_address',
                'esign_disclaimer_version',
                'signed_at',
            ]);
        });
    }
};
