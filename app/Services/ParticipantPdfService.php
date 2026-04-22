<?php

// ─── ParticipantPdfService ───────────────────────────────────────────────────
// Phase 14.1 (MVP roadmap). One-stop PDF generator for the four printable
// participant artifacts: facesheet, care plan, active medication list,
// allergy list. Shared Blade layout at resources/views/pdfs/_participant_layout.blade.php.
//
// Each method returns the raw PDF bytes. Controllers stream them; nobody is
// required to persist to Documents (operators can do that separately if they
// want a copy in the chart).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Participant;
use Barryvdh\DomPDF\Facade\Pdf;

class ParticipantPdfService
{
    public const KINDS = ['facesheet', 'care_plan', 'medication_list', 'allergy_list'];

    public function render(Participant $participant, string $kind): string
    {
        return match ($kind) {
            'facesheet'       => $this->facesheet($participant),
            'care_plan'       => $this->carePlan($participant),
            'medication_list' => $this->medicationList($participant),
            'allergy_list'    => $this->allergyList($participant),
            default           => throw new \InvalidArgumentException("Unknown PDF kind: {$kind}"),
        };
    }

    public function facesheet(Participant $participant): string
    {
        $participant->load(['site', 'activeFlags']);
        $address = $participant->addresses()->where('is_primary', true)->first()
            ?? $participant->addresses()->first();

        $data = [
            'participant'  => $participant,
            'address'      => $address,
            'flags'        => $participant->activeFlags ?? collect(),
            'problems'     => $participant->problems()->where('status', 'active')->limit(10)->get(),
            'allergies'    => $participant->allergies()->where('is_active', true)->get(),
            'medications'  => $participant->medications()->where('status', 'active')->limit(15)->get(),
            'generated_at' => now(),
        ];
        return $this->pdf('pdfs.facesheet', $data);
    }

    public function carePlan(Participant $participant): string
    {
        $participant->load(['site']);
        // Newest active plan with goals eager-loaded
        $plan = $participant->carePlans()
            ->whereIn('status', ['active', 'under_review'])
            ->with(['goals', 'approvedBy'])
            ->latest('effective_date')
            ->first()
            ?? $participant->carePlans()->latest()->first();

        $data = [
            'participant'  => $participant,
            'address'      => null,
            'carePlan'     => $plan,
            'generated_at' => now(),
        ];
        return $this->pdf('pdfs.care-plan', $data);
    }

    public function medicationList(Participant $participant): string
    {
        $participant->load(['site']);
        $data = [
            'participant'  => $participant,
            'address'      => null,
            'medications'  => $participant->medications()
                ->whereIn('status', ['active', 'prn'])
                ->orderByRaw("CASE WHEN is_controlled THEN 0 ELSE 1 END")
                ->orderBy('drug_name')
                ->get(),
            'allergies'    => $participant->allergies()->where('is_active', true)->get(),
            'generated_at' => now(),
        ];
        return $this->pdf('pdfs.medication-list', $data);
    }

    public function allergyList(Participant $participant): string
    {
        $participant->load(['site']);
        $data = [
            'participant'  => $participant,
            'address'      => null,
            'allergies'    => $participant->allergies()->get(),
            'generated_at' => now(),
        ];
        return $this->pdf('pdfs.allergy-list', $data);
    }

    private function pdf(string $view, array $data): string
    {
        return (string) Pdf::loadView($view, $data)
            ->setPaper('letter')
            ->setOption('isPhpEnabled', false)
            ->output();
    }
}
