<?php

namespace App\Services\EncounterDataSubmission;

use App\Models\EdiBatch;

class DirectCmsEncounterDataGateway implements EncounterDataGateway
{
    public function submit(EdiBatch $batch): array
    {
        throw new \RuntimeException(
            'DirectCmsEncounterDataGateway: awaiting CMS EDS Trading Partner Agreement + connectivity setup. ' .
            'Activate via env ENCOUNTER_DATA_DRIVER=direct_cms once contract signed.'
        );
    }

    public function name(): string { return 'direct_cms'; }
}
