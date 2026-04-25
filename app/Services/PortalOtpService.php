<?php

// ─── PortalOtpService — Phase L1 ────────────────────────────────────────────
// OTP send/verify for ParticipantPortalUser. Mirrors OtpService but targets
// the portal-user subject. Email-only in v1; phone can share the same flow.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\ParticipantPortalUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PortalOtpService
{
    public const CODE_LENGTH    = 6;
    public const EXPIRE_MINUTES = 10;
    public const MAX_ATTEMPTS   = 3;

    /** Always returns true to avoid leaking whether the email is registered. */
    public function sendOtp(string $email, string $ip): bool
    {
        $user = ParticipantPortalUser::where('email', $email)
            ->where('is_active', true)->first();

        if (! $user) return true;

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        // Invalidate any prior open codes for this portal user.
        OtpCode::where('participant_portal_user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        OtpCode::create([
            'participant_portal_user_id' => $user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRE_MINUTES),
            'ip_address' => $ip,
            'attempts'   => 0,
        ]);

        // Phase O10 — branded email delivery. Demo deliverability via Mailpit.
        // Production needs a HIPAA-BAA mail vendor (paywall item 12). The
        // wrapper try/catch keeps the OTP itself valid even if dispatch fails;
        // the row is already persisted above and admins can retrieve it.
        try {
            Mail::to($user->email)->send(new \App\Mail\PortalOtpMail($user, $code));
        } catch (\Throwable $e) {
            \Log::warning("Portal OTP mail dispatch failed for {$user->email}: " . $e->getMessage());
        }

        AuditLog::record(
            action: 'portal.otp_sent',
            tenantId: $user->tenant_id,
            userId: null,
            resourceType: 'participant_portal_user',
            resourceId: $user->id,
            description: "Portal OTP sent to {$user->email}",
        );
        return true;
    }

    /**
     * @return array{success: bool, user: ?ParticipantPortalUser, error: ?string}
     */
    public function verifyOtp(string $email, string $code, string $ip): array
    {
        $user = ParticipantPortalUser::where('email', $email)
            ->where('is_active', true)->first();
        if (! $user) return $this->failure('Invalid credentials.');

        $otp = OtpCode::where('participant_portal_user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')->first();

        if (! $otp) return $this->failure('Code has expired or was not requested.');

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['used_at' => now()]);
            return $this->failure('Too many failed attempts.');
        }

        if (! $otp->verifyCode($code)) {
            $otp->increment('attempts');
            $remaining = self::MAX_ATTEMPTS - $otp->fresh()->attempts;
            return $this->failure("Incorrect code. {$remaining} attempt(s) remaining.");
        }

        $otp->markUsed();
        $user->update(['last_login_at' => now()]);

        AuditLog::record(
            action: 'portal.otp_login',
            tenantId: $user->tenant_id,
            userId: null,
            resourceType: 'participant_portal_user',
            resourceId: $user->id,
            description: "Portal OTP login success ({$user->email})",
        );

        return ['success' => true, 'user' => $user, 'error' => null];
    }

    private function failure(string $e): array
    {
        return ['success' => false, 'user' => null, 'error' => $e];
    }
}
