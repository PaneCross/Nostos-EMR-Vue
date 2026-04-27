<?php

// ─── EncounterDataGateway (interface) : Phase S4 ────────────────────────────
// Vendor-agnostic CMS Encounter Data Submission contract. NullEncounter is
// the safe default : stages 837P file for manual upload + returns honest
// submission stub. Real adapters (DirectCMS, Availity, ChangeHealthcare)
// require contract + credentials.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\EncounterDataSubmission;

use App\Models\EdiBatch;

interface EncounterDataGateway
{
    /** Submit an EdiBatch (837P) and return ['status'=>..., 'reference'=>..., 'payload'=>...]. */
    public function submit(EdiBatch $batch): array;

    /** Driver name (used in EdiBatch::clearinghouse_reference + audit logs). */
    public function name(): string;
}
