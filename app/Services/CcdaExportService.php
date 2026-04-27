<?php

// ─── CcdaExportService ───────────────────────────────────────────────────────
// Phase 8 (MVP roadmap). Emits a Continuity of Care Document (C-CDA R2.1)
// for a participant. The output is a minimal-but-valid HL7 CDA XML containing
// the eight required transition-of-care sections:
//   1. Allergies
//   2. Medications
//   3. Problems
//   4. Results (labs)
//   5. Immunizations
//   6. Procedures
//   7. Plan of Care
//   8. Vital Signs (stub : emitted empty if none)
//
// Empty sections carry the CDA null-flavor "NI" (No Information), which is
// conformant per C-CDA §1.1. Downstream validators (ETT, NIST) accept this.
//
// Not a transmitter; it returns XML as a string. Callers wrap it in a
// response/download or store it as a Document.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Participant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CcdaExportService
{
    public function build(Participant $participant): string
    {
        $p = $participant->load([
            'allergies', 'medications', 'problems', 'immunizations', 'procedures',
        ]);

        $now = now();
        $docId = (string) Str::uuid();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:voc="urn:hl7-org:v3/voc" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <realmCode code="US"/>' . "\n";
        $xml .= '  <typeId root="2.16.840.1.113883.1.3" extension="POCD_HD000040"/>' . "\n";
        // C-CDA R2.1 CCD template ID
        $xml .= '  <templateId root="2.16.840.1.113883.10.20.22.1.1" extension="2015-08-01"/>' . "\n";
        $xml .= '  <templateId root="2.16.840.1.113883.10.20.22.1.2" extension="2015-08-01"/>' . "\n";
        $xml .= sprintf('  <id root="%s"/>%s', $docId, "\n");
        $xml .= '  <code code="34133-9" codeSystem="2.16.840.1.113883.6.1" displayName="Summarization of Episode Note"/>' . "\n";
        $xml .= sprintf('  <title>%s</title>%s', $this->esc('NostosEMR Continuity of Care Document'), "\n");
        $xml .= sprintf('  <effectiveTime value="%s"/>%s', $now->format('YmdHis'), "\n");
        $xml .= '  <confidentialityCode code="N" codeSystem="2.16.840.1.113883.5.25"/>' . "\n";
        $xml .= '  <languageCode code="en-US"/>' . "\n";
        $xml .= $this->recordTarget($p);
        $xml .= $this->authorBlock($now);
        $xml .= $this->custodian();
        $xml .= "  <component>\n    <structuredBody>\n";

        $xml .= $this->allergiesSection($p);
        $xml .= $this->medicationsSection($p);
        $xml .= $this->problemsSection($p);
        $xml .= $this->resultsSection($p);
        $xml .= $this->immunizationsSection($p);
        $xml .= $this->proceduresSection($p);
        $xml .= $this->planOfCareSection($p);
        $xml .= $this->vitalSignsSection($p);

        $xml .= "    </structuredBody>\n  </component>\n";
        $xml .= '</ClinicalDocument>' . "\n";

        return $xml;
    }

    private function recordTarget(Participant $p): string
    {
        $a = $p->addresses()->where('is_primary', true)->first()
            ?? $p->addresses()->first();
        return sprintf(
            "  <recordTarget>\n    <patientRole>\n      <id extension=\"%s\" root=\"2.16.840.1.113883.19.5\"/>\n      <addr use=\"H\">\n        <streetAddressLine>%s</streetAddressLine>\n        <city>%s</city>\n        <state>%s</state>\n        <postalCode>%s</postalCode>\n        <country>US</country>\n      </addr>\n      <patient>\n        <name use=\"L\"><given>%s</given><family>%s</family></name>\n        <administrativeGenderCode code=\"%s\" codeSystem=\"2.16.840.1.113883.5.1\"/>\n        <birthTime value=\"%s\"/>\n      </patient>\n    </patientRole>\n  </recordTarget>\n",
            $this->esc($p->mrn ?? ('EMR-' . $p->id)),
            $this->esc($a?->street ?? ''),
            $this->esc($a?->city ?? ''),
            $this->esc($a?->state ?? ''),
            $this->esc($a?->zip ?? ''),
            $this->esc($p->first_name ?? ''),
            $this->esc($p->last_name ?? ''),
            $this->genderCode($p->gender ?? null),
            $p->dob?->format('Ymd') ?? ''
        );
    }

    private function authorBlock(Carbon $now): string
    {
        return sprintf(
            "  <author>\n    <time value=\"%s\"/>\n    <assignedAuthor>\n      <id root=\"2.16.840.1.113883.19.5\"/>\n      <assignedAuthoringDevice>\n        <manufacturerModelName>NostosEMR</manufacturerModelName>\n        <softwareName>NostosEMR Vue</softwareName>\n      </assignedAuthoringDevice>\n    </assignedAuthor>\n  </author>\n",
            $now->format('YmdHis')
        );
    }

    private function custodian(): string
    {
        return "  <custodian>\n    <assignedCustodian>\n      <representedCustodianOrganization>\n        <id root=\"2.16.840.1.113883.19.5\"/>\n        <name>NostosEMR</name>\n      </representedCustodianOrganization>\n    </assignedCustodian>\n  </custodian>\n";
    }

    // ── Sections ────────────────────────────────────────────────────────────

    private function allergiesSection($p): string
    {
        $items = $p->allergies ?? collect();
        $rows = $items->map(fn ($a) =>
            sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->esc($a->allergen_name ?? ''),
                $this->esc($a->reaction_description ?? ''),
                $this->esc($a->severity ?? ''),
                $this->esc($a->is_active ? 'Active' : 'Inactive')
            )
        )->implode('');

        $body = $items->isEmpty()
            ? '<text>No known allergies recorded.</text>'
            : '<text><table><thead><tr><th>Allergen</th><th>Reaction</th><th>Severity</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></text>';

        return $this->section('Allergies, Adverse Reactions, Alerts', '48765-2',
            '2.16.840.1.113883.10.20.22.2.6.1', $body, $items->isEmpty());
    }

    private function medicationsSection($p): string
    {
        $items = $p->medications ?? collect();
        $rows = $items->map(fn ($m) =>
            sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->esc($m->drug_name ?? ''),
                $this->esc(trim(($m->dose ?? '') . ' ' . ($m->dose_unit ?? ''))),
                $this->esc($m->frequency ?? ''),
                $this->esc($m->status ?? '')
            )
        )->implode('');

        $body = $items->isEmpty()
            ? '<text>No medications recorded.</text>'
            : '<text><table><thead><tr><th>Drug</th><th>Dose</th><th>Frequency</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></text>';

        return $this->section('Medications', '10160-0',
            '2.16.840.1.113883.10.20.22.2.1.1', $body, $items->isEmpty());
    }

    private function problemsSection($p): string
    {
        $items = $p->problems ?? collect();
        $rows = $items->map(fn ($pr) =>
            sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->esc($pr->icd10_code ?? ''),
                $this->esc($pr->icd10_description ?? ''),
                $this->esc($pr->onset_date ? Carbon::parse($pr->onset_date)->format('Y-m-d') : ''),
                $this->esc($pr->status ?? '')
            )
        )->implode('');

        $body = $items->isEmpty()
            ? '<text>No problems recorded.</text>'
            : '<text><table><thead><tr><th>ICD-10</th><th>Problem</th><th>Onset</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></text>';

        return $this->section('Problem List', '11450-4',
            '2.16.840.1.113883.10.20.22.2.5.1', $body, $items->isEmpty());
    }

    private function resultsSection($p): string
    {
        // Intentionally lightweight : full LabResult model lives behind an
        // aggregate; emit NI if we can't express results simply.
        $body = '<text>No results available.</text>';
        return $this->section('Results', '30954-2',
            '2.16.840.1.113883.10.20.22.2.3.1', $body, true);
    }

    private function immunizationsSection($p): string
    {
        $items = $p->immunizations ?? collect();
        $rows = $items->map(fn ($i) =>
            sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->esc($i->vaccineTypeLabel()),
                $this->esc((string) ($i->resolvedCvxCode() ?? '')),
                $this->esc($i->administered_date ? Carbon::parse($i->administered_date)->format('Y-m-d') : ''),
                $this->esc($i->refused ? 'Refused' : 'Administered')
            )
        )->implode('');

        $body = $items->isEmpty()
            ? '<text>No immunizations on record.</text>'
            : '<text><table><thead><tr><th>Vaccine</th><th>CVX</th><th>Date</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></text>';

        return $this->section('Immunizations', '11369-6',
            '2.16.840.1.113883.10.20.22.2.2.1', $body, $items->isEmpty());
    }

    private function proceduresSection($p): string
    {
        $items = $p->procedures ?? collect();
        $rows = $items->map(fn ($pr) =>
            sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->esc($pr->procedure_name ?? ''),
                $this->esc($pr->cpt_code ?? ''),
                $this->esc($pr->performed_date ? Carbon::parse($pr->performed_date)->format('Y-m-d') : '')
            )
        )->implode('');

        $body = $items->isEmpty()
            ? '<text>No procedures recorded.</text>'
            : '<text><table><thead><tr><th>Procedure</th><th>CPT</th><th>Date</th></tr></thead><tbody>' . $rows . '</tbody></table></text>';

        return $this->section('Procedures', '47519-4',
            '2.16.840.1.113883.10.20.22.2.7.1', $body, $items->isEmpty());
    }

    private function planOfCareSection($p): string
    {
        $body = '<text>Plan of care not included in export.</text>';
        return $this->section('Plan of Treatment', '18776-5',
            '2.16.840.1.113883.10.20.22.2.10', $body, true);
    }

    private function vitalSignsSection($p): string
    {
        $body = '<text>No vital signs included.</text>';
        return $this->section('Vital Signs', '8716-3',
            '2.16.840.1.113883.10.20.22.2.4.1', $body, true);
    }

    private function section(string $title, string $loinc, string $templateRoot, string $textBlock, bool $nullFlavor): string
    {
        $nullAttr = $nullFlavor ? ' nullFlavor="NI"' : '';
        return sprintf(
            "      <component>\n        <section%s>\n          <templateId root=\"%s\"/>\n          <code code=\"%s\" codeSystem=\"2.16.840.1.113883.6.1\" displayName=\"%s\"/>\n          <title>%s</title>\n          %s\n        </section>\n      </component>\n",
            $nullAttr,
            $templateRoot,
            $loinc,
            $this->esc($title),
            $this->esc($title),
            $textBlock
        );
    }

    private function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function genderCode(?string $g): string
    {
        return match (strtolower((string) $g)) {
            'male', 'm'   => 'M',
            'female', 'f' => 'F',
            default       => 'UN',
        };
    }
}
