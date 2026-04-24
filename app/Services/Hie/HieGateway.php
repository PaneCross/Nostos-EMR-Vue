<?php

// ─── HieGateway (interface) — Phase M3 ───────────────────────────────────────
// Vendor-agnostic HIE (Health Information Exchange) contract. Real adapters
// (Sequoia/CommonWell/Carequality) implement this once contracts + SDKs exist.
// NullHieGateway is the default: stores CCDs locally for manual pickup.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Hie;

use App\Models\Participant;

interface HieGateway
{
    public function name(): string;

    /**
     * Publish a participant's CCD to the HIE. Real adapters POST the document
     * to the network; null gateway stores the document reference locally.
     *
     * @return array{ok: bool, transmission_id: ?string, message: ?string}
     */
    public function publishCcd(Participant $participant, string $ccdXml): array;

    /**
     * Query the HIE for any documents about a participant (by MRN or
     * demographic match). Real adapters return document references; null
     * gateway returns [].
     *
     * @return array<int, array{title: string, author: string, date: string, url: ?string}>
     */
    public function queryDocuments(Participant $participant): array;

    /** True if the configured credentials look valid (auth probe). */
    public function healthCheck(): bool;
}
