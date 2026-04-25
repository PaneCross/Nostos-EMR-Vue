<?php

namespace App\Services\EncounterDataSubmission;

use App\Models\EdiBatch;

class AvailityEncounterDataGateway implements EncounterDataGateway
{
    public function submit(EdiBatch $batch): array
    {
        throw new \RuntimeException(
            'AvailityEncounterDataGateway: awaiting clearinghouse contract + Availity CMS-EDS routing setup. ' .
            'Activate via env ENCOUNTER_DATA_DRIVER=availity once contract signed.'
        );
    }

    public function name(): string { return 'availity'; }
}
