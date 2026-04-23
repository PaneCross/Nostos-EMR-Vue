<?php

// ─── WristbandController ─────────────────────────────────────────────────────
// Phase B4. Renders a simple printable wristband PDF with:
//   - Participant name + DOB + MRN
//   - BCMA barcode (rendered as a Code-128-style QR via BaconQrCode SVG)
//   - Known allergy alert (from participant.allergies if any active)
//
// PDF output via DomPDF. Designed for a standard 4" x 1" wristband-printer
// layout but works on letter paper for spot printing.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Allergy;
use App\Models\Participant;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WristbandController extends Controller
{
    public function show(Request $request, Participant $participant): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($participant->tenant_id !== $user->tenant_id, 403);

        // Backfill if missing so a print never fails for a lack of a barcode.
        if (! $participant->barcode_value) {
            $participant->barcode_value = "PT-{$participant->tenant_id}-{$participant->mrn}";
            $participant->save();
        }

        $allergies = class_exists(Allergy::class)
            ? Allergy::where('participant_id', $participant->id)
                ->where('is_active', true)
                ->orderBy('allergen_name')
                ->get(['allergen_name', 'severity'])
            : collect();

        // Render QR to inline SVG (no file I/O, no GD, no imagick needed).
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(180),
            new SvgImageBackEnd()
        ));
        $qrSvg = $writer->writeString($participant->barcode_value);

        $pdf = Pdf::loadView('pdfs.wristband', [
            'participant' => $participant,
            'allergies'   => $allergies,
            'qr_svg'      => $qrSvg,
        ])->setPaper([0, 0, 288, 144], 'landscape'); // 4" x 2" @ 72dpi

        return $pdf->stream("wristband-{$participant->mrn}.pdf");
    }
}
