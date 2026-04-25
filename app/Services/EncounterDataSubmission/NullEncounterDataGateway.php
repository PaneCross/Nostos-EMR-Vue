<?php

namespace App\Services\EncounterDataSubmission;

use App\Models\EdiBatch;

class NullEncounterDataGateway implements EncounterDataGateway
{
    public function submit(EdiBatch $batch): array
    {
        return [
            'status'    => 'staged',
            'reference' => 'null-' . $batch->id,
            'payload'   => [
                'message' => 'Null gateway — 837P staged in emr_edi_batches.file_content for manual operator upload to CMS EDS portal.',
            ],
        ];
    }

    public function name(): string { return 'null'; }
}
