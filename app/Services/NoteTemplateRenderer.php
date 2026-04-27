<?php

// ─── NoteTemplateRenderer ────────────────────────────────────────────────────
// Phase B7. Renders a NoteTemplate body by substituting {{placeholder}} tokens
// with participant-derived content. Unknown placeholders are left intact so
// the clinician can see + fill them manually.
//
// Named NoteTemplateRenderer (not NoteTemplateService) because the existing
// NoteTemplateService serves config-based structured-field schemas from a
// different pre-B7 design. Both co-exist: config templates for schemas,
// DB templates (Phase B7) for free-form body Markdown.
//
// Supported placeholders:
//   {{participant.name}}          : "Last, First"
//   {{participant.preferred}}     : preferred_name || first_name
//   {{participant.mrn}}
//   {{participant.dob}}           : MM/DD/YYYY
//   {{participant.age}}
//   {{today}}                     : YYYY-MM-DD
//   {{provider.name}}             : logged-in user's "First Last"
//   {{active_meds_list}}          : bullet list of active Medication.drug_name (max 10)
//   {{latest_vitals}}             : "BP xxx/xx, HR xx, Temp xx, SpO2 xx%, recorded …"
//   {{problem_list}}              : bullet list of active Problem.icd10_description (max 10)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Medication;
use App\Models\NoteTemplate;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\User;
use App\Models\Vital;

class NoteTemplateRenderer
{
    public function render(NoteTemplate $template, Participant $participant, ?User $user = null): string
    {
        $vars = $this->buildVariables($participant, $user);
        $body = $template->body_markdown;
        foreach ($vars as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }
        return $body;
    }

    /** @return array<string, string> */
    private function buildVariables(Participant $participant, ?User $user): array
    {
        $name = trim("{$participant->last_name}, {$participant->first_name}");
        $preferred = $participant->preferred_name ?: $participant->first_name;

        $meds = Medication::where('participant_id', $participant->id)
            ->where('status', 'active')
            ->orderBy('drug_name')->limit(10)
            ->pluck('drug_name')
            ->map(fn ($n) => "- {$n}")
            ->implode("\n");

        $problems = Problem::where('participant_id', $participant->id)
            ->where('status', 'active')
            ->orderBy('icd10_code')->limit(10)
            ->get(['icd10_code', 'icd10_description'])
            ->map(fn ($p) => "- {$p->icd10_description} ({$p->icd10_code})")
            ->implode("\n");

        $latest = Vital::where('participant_id', $participant->id)
            ->orderByDesc('recorded_at')->first();
        $latestVitals = $latest
            ? sprintf(
                'BP %s/%s, HR %s, Temp %s°F, SpO2 %s%% (recorded %s)',
                $latest->bp_systolic ?? ':',
                $latest->bp_diastolic ?? ':',
                $latest->pulse ?? ':',
                $latest->temperature_f ?? ':',
                $latest->o2_saturation ?? ':',
                $latest->recorded_at?->format('Y-m-d H:i') ?? ':',
            )
            : ':';

        $age = $participant->dob ? (int) $participant->dob->diffInYears(now()) : ':';

        return [
            'participant.name'      => $name,
            'participant.preferred' => $preferred ?: ':',
            'participant.mrn'       => $participant->mrn ?? ':',
            'participant.dob'       => $participant->dob?->format('m/d/Y') ?? ':',
            'participant.age'       => (string) $age,
            'today'                 => now()->toDateString(),
            'provider.name'         => $user ? trim("{$user->first_name} {$user->last_name}") : ':',
            'active_meds_list'      => $meds ?: '- None documented',
            'latest_vitals'         => $latestVitals,
            'problem_list'          => $problems ?: '- None documented',
        ];
    }
}
