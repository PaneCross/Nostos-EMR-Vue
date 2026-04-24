<?php

// ─── StateAdapter — Phase M6 ────────────────────────────────────────────────
// Per-state Medicaid adapter contract. Each real state implementation
// transforms a standard 837P payload into the state-specific submission format
// (file layout, header, delimiters) required by that state's portal.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\StateMedicaid;

interface StateAdapter
{
    public function stateCode(): string;
    public function format(): string;

    /**
     * Transform an 837P EDI string (or payload array) into the state format.
     * Returns a plain string the operator can upload. MVP adapters just add
     * a header/footer wrapping the EDI content; real adapters will fully
     * reformat into state layout (e.g., NY eMedNY fixed-width, FL MMIS XML).
     */
    public function transform(string $payload, array $metadata = []): string;
}
