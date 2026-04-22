<?php

// ─── AdvanceDirectivePdfService ──────────────────────────────────────────────
// Phase 8 (MVP roadmap). Generates a pre-filled advance-directive PDF
// (DNR or POLST baseline) from participant demographics + currently recorded
// advance_directive_* fields on the Participant model.
//
// DomPDF renders a Blade template (`resources/views/pdfs/advance-directive.blade.php`)
// with participant data merged in. Output is a PDF binary string.
//
// Scope per Phase 8:
//   - DNR + POLST baseline (single combined template; POLST sections hidden
//     when type=dnr, DNR-only sections hidden when type=polst)
//   - Healthcare proxy + living will supported via the same form fields
//   - State-specific POLST variants are DEFERRED to Phase 8 extensions; many
//     states have their own official PDF forms and direct printed use is
//     the right answer until individual state templates are authored
//
// Honest labeling: the rendered PDF carries a "PACE-generated facsimile" footer
// until an officially-approved state form is produced.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Participant;
use Barryvdh\DomPDF\Facade\Pdf;

class AdvanceDirectivePdfService
{
    public const TYPES = ['dnr', 'polst', 'living_will', 'healthcare_proxy', 'combined'];

    public function render(Participant $participant, string $type = 'dnr'): string
    {
        $type = in_array($type, self::TYPES) ? $type : 'dnr';

        $address = $participant->addresses()->where('is_primary', true)->first()
            ?? $participant->addresses()->first();

        $data = [
            'participant'  => $participant,
            'address'      => $address,
            'type'         => $type,
            'type_label'   => $this->typeLabel($type),
            'generated_at' => now(),
        ];

        $pdf = Pdf::loadView('pdfs.advance-directive', $data)
            ->setPaper('letter')
            ->setOption('isPhpEnabled', false);

        return (string) $pdf->output();
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'dnr'             => 'Do Not Resuscitate (DNR) Order',
            'polst'           => 'Physician Orders for Life-Sustaining Treatment (POLST)',
            'living_will'     => 'Living Will',
            'healthcare_proxy'=> 'Healthcare Proxy',
            'combined'        => 'Combined Advance Directive',
            default           => 'Advance Directive',
        };
    }
}
