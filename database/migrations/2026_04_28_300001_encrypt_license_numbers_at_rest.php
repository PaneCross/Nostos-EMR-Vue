<?php

// ─── Migration: encrypt license_number at rest ───────────────────────────────
// DEA numbers in particular uniquely identify a prescriber and are sensitive
// PII ; state license numbers are less sensitive but still should not sit in
// plaintext. Bumps the column to TEXT to fit Laravel's encrypted ciphertext
// (encrypted strings are typically 4-5x the plaintext length), then re-saves
// every existing row through the model so the new `encrypted` cast applies.
//
// down() : decrypt + revert column to varchar(80). Some rows may be lossy if
// they happened to contain non-UTF-8 bytes (very unlikely for license numbers
// which are alphanumeric).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bump column type to TEXT before adding cast. Encrypted strings can
        // run 4-5x the original length ; varchar(80) is too narrow.
        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->text('license_number')->nullable()->change();
        });

        // Re-encrypt existing rows in place. We read the raw plaintext value
        // directly via DB facade (bypassing the model so the cast doesn't try
        // to decrypt unencrypted data), then write through the model so the
        // new cast encrypts it on save.
        $rows = DB::table('emr_staff_credentials')
            ->whereNotNull('license_number')
            ->get(['id', 'license_number']);

        foreach ($rows as $r) {
            // Use raw DB update so we don't trigger model events / observers.
            // Laravel's Crypt facade handles encryption with the app key.
            $encrypted = \Illuminate\Support\Facades\Crypt::encryptString($r->license_number);
            DB::table('emr_staff_credentials')->where('id', $r->id)
                ->update(['license_number' => $encrypted]);
        }
    }

    public function down(): void
    {
        // Decrypt back to plaintext, then narrow the column type.
        $rows = DB::table('emr_staff_credentials')
            ->whereNotNull('license_number')
            ->get(['id', 'license_number']);

        foreach ($rows as $r) {
            try {
                $plain = \Illuminate\Support\Facades\Crypt::decryptString($r->license_number);
                DB::table('emr_staff_credentials')->where('id', $r->id)
                    ->update(['license_number' => $plain]);
            } catch (\Throwable $e) {
                // Already plaintext or unrecoverable ; leave as-is.
            }
        }

        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->string('license_number', 80)->nullable()->change();
        });
    }
};
