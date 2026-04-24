<?php

namespace App\Services\Sms;

interface SmsGateway
{
    public function name(): string;

    /**
     * Send an SMS. Returns delivery attempt metadata.
     * @return array{sent: bool, channel: string, error?: string, message_id?: string}
     */
    public function send(string $toPhone, string $body): array;
}
