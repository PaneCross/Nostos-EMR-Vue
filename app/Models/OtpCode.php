<?php

// ─── OtpCode ──────────────────────────────────────────────────────────────────
// One-Time Password (OTP) record used for passwordless staff login.
//
// When a user requests login, OtpService generates a 6-digit code, hashes it
// (bcrypt) into `code_hash`, and emails the plaintext to the user. On verify,
// the hash is compared, expiry is checked, and `used_at` is stamped to prevent
// reuse. `attempts` tracks failed verifications against this row.
//
// Notable rules:
//  - Codes expire (default 10 min) and are single-use — `isValid()` enforces both.
//  - Plaintext code never persists; only the hash is stored. HIPAA §164.312
//    technical safeguard for authenticator secrets.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    protected $table = 'shared_otp_codes';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'participant_portal_user_id',
        'code_hash',
        'expires_at',
        'used_at',
        'ip_address',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
        'attempts'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function verifyCode(string $plainCode): bool
    {
        return Hash::check($plainCode, $this->code_hash);
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
