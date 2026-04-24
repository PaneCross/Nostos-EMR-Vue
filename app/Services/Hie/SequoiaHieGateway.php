<?php

namespace App\Services\Hie;

use App\Models\Participant;

/**
 * Sequoia / Carequality HIE gateway stub. Activated when contracts + SDK
 * credentials land. Every method throws until the SDK is wired.
 */
class SequoiaHieGateway implements HieGateway
{
    public function name(): string { return 'sequoia'; }

    public function publishCcd(Participant $participant, string $ccdXml): array
    {
        throw new \RuntimeException('SequoiaHieGateway: awaiting Carequality/Sequoia contract + SDK.');
    }

    public function queryDocuments(Participant $participant): array
    {
        throw new \RuntimeException('SequoiaHieGateway: awaiting Carequality/Sequoia contract + SDK.');
    }

    public function healthCheck(): bool
    {
        return false;
    }
}
