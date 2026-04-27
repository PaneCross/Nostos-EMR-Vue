<?php

// ─── TwilioSmsGateway ────────────────────────────────────────────────────────
// Phase G7 : stub. Will throw on use until credentials are configured AND a
// Twilio SDK dependency is added. Pattern match Phase 12's ClearinghouseGateway
// stubs: compile-safe, throws at call time to prevent silent failure.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Sms;

use RuntimeException;

class TwilioSmsGateway implements SmsGateway
{
    public function name(): string { return 'twilio'; }

    public function send(string $toPhone, string $body): array
    {
        if (empty(config('services.sms.twilio.account_sid')) || empty(config('services.sms.twilio.auth_token'))) {
            throw new RuntimeException('Twilio credentials not configured (services.sms.twilio.*). Swap to NullSmsGateway or fill env.');
        }
        // Deliberate: we do NOT call Twilio here until SDK is added.
        // Adding twilio/sdk is a paywall decision (Twilio is paid per SMS).
        throw new RuntimeException('TwilioSmsGateway stub : composer require twilio/sdk and wire before use.');
    }
}
