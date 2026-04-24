<?php

namespace App\Services\Sms;

// Safe default: no SMS is sent. Responses captured via portal/phone instead.
class NullSmsGateway implements SmsGateway
{
    public function name(): string { return 'null'; }

    public function send(string $toPhone, string $body): array
    {
        return ['sent' => false, 'channel' => 'null', 'error' => 'no_sms_gateway_configured'];
    }
}
