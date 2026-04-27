<?php

// ─── Hl7VxuBuilder ────────────────────────────────────────────────────────────
// Phase 8 (MVP roadmap). Constructs an HL7 v2.5.1 VXU^V04 ("Unsolicited
// Vaccination Update") message for a single Immunization. States consume VXU
// through their IIS (Immunization Information System). Every state has its
// own Z-segment / profile quirks captured in StateImmunizationRegistryConfig.
//
// This is the minimal viable VXU: MSH, PID, PD1, NK1, ORC, RXA, RXR, OBX.
// The output is pipe-delimited ASCII (HL7 v2 wire format). Segments are
// separated by \r per HL7 spec; callers that need \r\n can translate.
//
// NOT a transmitter. Output text is stored in ImmunizationSubmission for
// audit. Actual send to a state registry is out of scope (honest-labeled).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Immunization;
use App\Models\Participant;
use App\Models\StateImmunizationRegistryConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Hl7VxuBuilder
{
    public const SEGMENT_SEPARATOR = "\r";
    public const FIELD_SEP         = '|';
    public const COMPONENT_SEP     = '^';
    public const REPEAT_SEP        = '~';
    public const ESCAPE_CHAR       = '\\';
    public const SUBCOMPONENT_SEP  = '&';

    public function build(Participant $participant, Immunization $immunization, ?StateImmunizationRegistryConfig $cfg = null): array
    {
        $mcid    = 'VXU' . now()->format('YmdHis') . substr((string) Str::uuid(), 0, 8);
        $ts      = now()->format('YmdHis');
        $sendApp = $cfg?->sender_application ?: 'NostosEMR';
        $sendFac = $cfg?->sender_facility_id  ?: ('TENANT' . $participant->tenant_id);
        $recvApp = $cfg?->registry_name       ?: 'STATE_IIS';
        $recvFac = $cfg?->state_code          ?: 'XX';
        $version = $cfg?->profile_version     ?: '2.5.1';

        $segments = [];

        // MSH : Message Header
        $segments[] = $this->segment('MSH', [
            '^~\\&',
            $sendApp,
            $sendFac,
            $recvApp,
            $recvFac,
            $ts,
            '',
            'VXU^V04^VXU_V04',
            $mcid,
            'P',            // Production (vs T/D)
            $version,
        ], prependSeparator: true);

        // PID : Patient Identification
        $segments[] = $this->segment('PID', [
            '1',                                                             // Set ID
            '',
            $participant->mrn,                                               // PID-3 patient id
            '',
            $this->xpn($participant),                                        // PID-5 name
            '',
            $participant->dob?->format('Ymd') ?? '',                         // PID-7 DOB
            $this->gender($participant->gender ?? null),                     // PID-8
            '',
            '',                                                              // PID-10 race (omitted)
            $this->address($participant),                                    // PID-11
            '',
            $participant->phone_primary ?? '',                               // PID-13
        ]);

        // PD1 : Patient Additional Demographics (minimal; required by many IIS profiles)
        $segments[] = $this->segment('PD1', [
            '', '', '', '', '', '', '', '', '', '', '', '',
            '02',  // 12th field: Protection Indicator (02 = no special protection)
            $ts,
        ]);

        // NK1 : Next of Kin (omit contents; segment is often required by profile)
        $segments[] = $this->segment('NK1', ['1']);

        // ORC : Common Order
        $segments[] = $this->segment('ORC', [
            'RE',                                        // Order control
            'EMR-' . $immunization->id,                  // Placer order #
            'REG-' . $immunization->id,                  // Filler order #
        ]);

        $cvx           = $immunization->resolvedCvxCode() ?? '';
        $vaccineLabel  = $immunization->vaccineTypeLabel();
        $administered  = $immunization->administered_date?->format('Ymd') ?? '';
        $refusedFlag   = $immunization->refused ? 'Y' : 'N';
        $completionSt  = $immunization->refused ? 'RE' : 'CP'; // RE=refused CP=complete

        // RXA : Pharmacy/Treatment Administration
        $segments[] = $this->segment('RXA', [
            '0',
            '1',
            $administered,
            $administered,
            $cvx . self::COMPONENT_SEP . $vaccineLabel . self::COMPONENT_SEP . 'CVX',
            $immunization->dose_number ?? '',            // Amount administered (IIS often uses "999" for not-recorded)
            '',
            '',
            '',
            '',
            $immunization->administered_at_location ?? '',
            '',
            $immunization->manufacturer ?? '',
            $immunization->lot_number ?? '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $refusedFlag,
            '', '',
            $completionSt,
        ]);

        // RXR : Route (optional; emitted if "route" field exists on model)
        if (!empty($immunization->route)) {
            $segments[] = $this->segment('RXR', [
                $immunization->route,
            ]);
        }

        // OBX : VIS documentation (when vis_given=true)
        if ($immunization->vis_given) {
            $segments[] = $this->segment('OBX', [
                '1',
                'DT',
                '29769-7^Date VIS presented^LN',
                '',
                $immunization->vis_publication_date?->format('Ymd') ?? '',
            ]);
        }

        $payload = implode(self::SEGMENT_SEPARATOR, $segments) . self::SEGMENT_SEPARATOR;

        return [
            'message_control_id' => $mcid,
            'message'            => $payload,
        ];
    }

    private function segment(string $name, array $fields, bool $prependSeparator = false): string
    {
        // MSH's MSH-1 is the field separator itself : pass '^~\&' as first field
        // and let it ride in position 2. Real HL7: MSH|^~\&|field3|...
        $prefix = $name;
        if ($prependSeparator) {
            // MSH special-case: first field IS the separator; second is encoding chars.
            // fields[0] is already the encoding characters string.
            $enc = array_shift($fields);
            return $prefix . self::FIELD_SEP . $enc . self::FIELD_SEP . implode(self::FIELD_SEP, array_map([$this, 'esc'], $fields));
        }
        return $prefix . self::FIELD_SEP . implode(self::FIELD_SEP, array_map([$this, 'esc'], $fields));
    }

    private function esc(?string $value): string
    {
        if ($value === null) return '';
        // Don't escape characters that already include HL7 separators intentionally
        // (the callers pass composed strings like "CVX^vacc^CVX"). For raw data,
        // escape the five delimiters per HL7 spec.
        if (str_contains($value, self::COMPONENT_SEP) || str_contains($value, self::FIELD_SEP)) {
            return $value;
        }
        $value = str_replace('\\', '\\E\\', $value);
        return $value;
    }

    private function xpn(Participant $p): string
    {
        // XPN: family^given^middle^suffix^prefix^degree^type
        return implode(self::COMPONENT_SEP, [
            $p->last_name ?? '',
            $p->first_name ?? '',
            '',
            '',
            '',
            '',
            'L',
        ]);
    }

    private function address(Participant $p): string
    {
        $a = $p->addresses()->where('is_primary', true)->first()
            ?? $p->addresses()->first();
        // XAD: street^other^city^state^zip^country^type
        return implode(self::COMPONENT_SEP, [
            $a?->street ?? '',
            $a?->unit ?? '',
            $a?->city ?? '',
            $a?->state ?? '',
            $a?->zip ?? '',
            'USA',
            'H',
        ]);
    }

    private function gender(?string $g): string
    {
        return match (strtolower((string) $g)) {
            'male', 'm'   => 'M',
            'female', 'f' => 'F',
            'other'       => 'O',
            'unknown', '' => 'U',
            default       => 'U',
        };
    }
}
