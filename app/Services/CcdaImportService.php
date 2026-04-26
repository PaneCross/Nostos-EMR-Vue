<?php

// ─── CcdaImportService ───────────────────────────────────────────────────────
// Phase 8 (MVP roadmap). Parses a received C-CDA XML document (e.g. hospital
// discharge summary, transition-of-care) and extracts the three sections that
// matter for PACE med reconciliation:
//
//   1. Allergies
//   2. Medications
//   3. Problems
//
// Returns a parsed summary array. Does NOT write to the database — the
// MedReconciliation workflow decides which entries to accept, reconcile, or
// merge. This keeps import safe (clinical review required).
//
// Template OIDs recognized:
//   Allergies:     2.16.840.1.113883.10.20.22.2.6 / .6.1
//   Medications:   2.16.840.1.113883.10.20.22.2.1 / .1.1
//   Problem List:  2.16.840.1.113883.10.20.22.2.5 / .5.1
//
// LOINC code fallbacks are also matched if templateId is missing:
//   48765-2 allergies, 10160-0 medications, 11450-4 problems
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

class CcdaImportService
{
    /**
     * @return array{
     *   document_title: ?string,
     *   patient: array{first_name:?string,last_name:?string,dob:?string,gender:?string,mrn:?string},
     *   allergies: array<int, array{allergen:?string,reaction:?string,severity:?string,status:?string}>,
     *   medications: array<int, array{name:?string,dose:?string,frequency:?string,status:?string}>,
     *   problems: array<int, array{name:?string,icd10:?string,onset:?string,status:?string}>,
     *   warnings: array<int, string>
     * }
     */
    public function parse(string $xmlContent): array
    {
        $warnings = [];
        $result = [
            'document_title' => null,
            'patient'        => [
                'first_name' => null, 'last_name' => null,
                'dob' => null, 'gender' => null, 'mrn' => null,
            ],
            'allergies'      => [],
            'medications'    => [],
            'problems'       => [],
            'warnings'       => &$warnings,
        ];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Phase X4 — Audit-12 M1: protect against XXE (XML External Entity)
        // attacks. LIBXML_NONET disables network access; LIBXML_NOENT keeps
        // entity substitution off. CCDA imports never need external DTDs or
        // remote entity resolution.
        if (! @$dom->loadXML($xmlContent, LIBXML_NONET)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new \InvalidArgumentException('Invalid C-CDA XML: ' . ($errors[0]->message ?? 'parse error'));
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('cda', 'urn:hl7-org:v3');

        // Title
        $titles = $xp->query('//cda:ClinicalDocument/cda:title');
        if ($titles && $titles->length) {
            $result['document_title'] = trim($titles->item(0)->textContent);
        }

        // Patient demographics
        $given  = $xp->query('//cda:recordTarget/cda:patientRole/cda:patient/cda:name/cda:given');
        $family = $xp->query('//cda:recordTarget/cda:patientRole/cda:patient/cda:name/cda:family');
        if ($given && $given->length)   $result['patient']['first_name'] = trim($given->item(0)->textContent);
        if ($family && $family->length) $result['patient']['last_name']  = trim($family->item(0)->textContent);

        $dob = $xp->query('//cda:recordTarget/cda:patientRole/cda:patient/cda:birthTime/@value');
        if ($dob && $dob->length) {
            $v = $dob->item(0)->value;
            if (strlen($v) >= 8) {
                $result['patient']['dob'] = substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
            }
        }
        $g = $xp->query('//cda:recordTarget/cda:patientRole/cda:patient/cda:administrativeGenderCode/@code');
        if ($g && $g->length) {
            $result['patient']['gender'] = match ($g->item(0)->value) {
                'M' => 'male', 'F' => 'female', default => 'other',
            };
        }
        $mrn = $xp->query('//cda:recordTarget/cda:patientRole/cda:id/@extension');
        if ($mrn && $mrn->length) {
            $result['patient']['mrn'] = $mrn->item(0)->value;
        }

        // Sections
        $sections = $xp->query('//cda:component/cda:structuredBody/cda:component/cda:section');
        if (! $sections || $sections->length === 0) {
            $warnings[] = 'No structured-body sections found.';
            return $result;
        }

        foreach ($sections as $section) {
            $kind = $this->identifyDomSection($xp, $section);
            if ($kind === 'allergies') {
                $result['allergies'] = $this->parseDomTable($xp, $section, ['allergen', 'reaction', 'severity', 'status']);
            } elseif ($kind === 'medications') {
                $result['medications'] = $this->parseDomTable($xp, $section, ['name', 'dose', 'frequency', 'status']);
            } elseif ($kind === 'problems') {
                $result['problems'] = $this->parseDomProblems($xp, $section);
            }
        }

        if (empty($result['allergies']) && empty($result['medications']) && empty($result['problems'])) {
            $warnings[] = 'No recognizable sections (allergies, medications, problems) found.';
        }

        return $result;
    }

    private function identifyDomSection(\DOMXPath $xp, \DOMElement $section): ?string
    {
        $templates = $xp->query('./cda:templateId/@root', $section);
        foreach ($templates as $t) {
            $oid = $t->value;
            if (str_starts_with($oid, '2.16.840.1.113883.10.20.22.2.6')) return 'allergies';
            if (str_starts_with($oid, '2.16.840.1.113883.10.20.22.2.1')) return 'medications';
            if (str_starts_with($oid, '2.16.840.1.113883.10.20.22.2.5')) return 'problems';
        }
        $codes = $xp->query('./cda:code/@code', $section);
        foreach ($codes as $c) {
            if ($c->value === '48765-2') return 'allergies';
            if ($c->value === '10160-0') return 'medications';
            if ($c->value === '11450-4') return 'problems';
        }
        return null;
    }

    private function parseDomTable(\DOMXPath $xp, \DOMElement $section, array $cols): array
    {
        $rows = [];
        $trs = $xp->query('.//cda:text//cda:tbody/cda:tr', $section);
        foreach ($trs as $tr) {
            $tds = $xp->query('./cda:td', $tr);
            $entry = [];
            foreach ($cols as $i => $col) {
                $entry[$col] = ($tds && $tds->item($i)) ? trim($tds->item($i)->textContent) : null;
            }
            if (array_filter($entry)) $rows[] = $entry;
        }
        return $rows;
    }

    private function parseDomProblems(\DOMXPath $xp, \DOMElement $section): array
    {
        $rows = [];
        $trs = $xp->query('.//cda:text//cda:tbody/cda:tr', $section);
        foreach ($trs as $tr) {
            $tds = $xp->query('./cda:td', $tr);
            $r = [
                'icd10'  => ($tds && $tds->item(0)) ? trim($tds->item(0)->textContent) : null,
                'name'   => ($tds && $tds->item(1)) ? trim($tds->item(1)->textContent) : null,
                'onset'  => ($tds && $tds->item(2)) ? trim($tds->item(2)->textContent) : null,
                'status' => ($tds && $tds->item(3)) ? trim($tds->item(3)->textContent) : null,
            ];
            if (array_filter($r)) $rows[] = $r;
        }
        return $rows;
    }
}
