<?php

namespace App\Services\Hie;

use App\Models\Participant;

class NullHieGateway implements HieGateway
{
    public function name(): string { return 'null'; }

    public function publishCcd(Participant $participant, string $ccdXml): array
    {
        // MVP: no-op. Real gateway would ship to Sequoia/CommonWell.
        \Log::info("HIE null-publish CCD for participant {$participant->id} (" . strlen($ccdXml) . ' bytes)');
        return ['ok' => true, 'transmission_id' => 'null-' . $participant->id . '-' . time(), 'message' => 'Stored locally. No HIE configured.'];
    }

    public function queryDocuments(Participant $participant): array
    {
        return [];
    }

    public function healthCheck(): bool { return true; }
}
