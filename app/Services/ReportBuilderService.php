<?php

// ─── ReportBuilderService ────────────────────────────────────────────────────
// Phase G9. Cross-entity report builder with a safe whitelist.
// Never accepts raw SQL. Callers specify a base entity and optional joins +
// dimensions + measures; service builds a parameterized query.
//
// Output shape is Chart.js-ready: { labels: [...], datasets: [{label, data}] }.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReportBuilderService
{
    /** Whitelisted base entities → their SQL table + tenant_id column. */
    public const ENTITIES = [
        'participants'  => ['table' => 'emr_participants',   'tenant' => 'emr_participants.tenant_id'],
        'medications'   => ['table' => 'emr_medications',    'tenant' => 'emr_medications.tenant_id'],
        'problems'      => ['table' => 'emr_problems',       'tenant' => 'emr_problems.tenant_id'],
        'grievances'    => ['table' => 'emr_grievances',     'tenant' => 'emr_grievances.tenant_id'],
        'incidents'     => ['table' => 'emr_incidents',      'tenant' => 'emr_incidents.tenant_id'],
        'appointments'  => ['table' => 'emr_appointments',   'tenant' => 'emr_appointments.tenant_id'],
    ];

    /** Allowed joins (base → other). Each describes the JOIN clause. */
    public const JOINS = [
        'participants.medications' => ['table' => 'emr_medications',    'on' => 'emr_medications.participant_id = emr_participants.id'],
        'participants.problems'    => ['table' => 'emr_problems',       'on' => 'emr_problems.participant_id = emr_participants.id'],
        'participants.grievances'  => ['table' => 'emr_grievances',     'on' => 'emr_grievances.participant_id = emr_participants.id'],
        'participants.incidents'   => ['table' => 'emr_incidents',      'on' => 'emr_incidents.participant_id = emr_participants.id'],
        'participants.appointments'=> ['table' => 'emr_appointments',   'on' => 'emr_appointments.participant_id = emr_participants.id'],
    ];

    /** Whitelisted (table.column) dimensions for GROUP BY. */
    public const DIMENSIONS = [
        'emr_participants.gender', 'emr_participants.enrollment_status', 'emr_participants.site_id',
        'emr_medications.status', 'emr_medications.drug_name',
        'emr_problems.status', 'emr_problems.icd10_code',
        'emr_grievances.status', 'emr_grievances.urgency',
        'emr_incidents.incident_type', 'emr_incidents.status',
    ];

    /** Whitelisted aggregates. */
    public const MEASURES = [
        'count' => 'COUNT(*)',
        'count_distinct_participant' => 'COUNT(DISTINCT emr_participants.id)',
    ];

    public function run(int $tenantId, array $config): array
    {
        $entity = $config['entity'] ?? null;
        abort_unless(isset(self::ENTITIES[$entity]), 422, 'Unknown entity.');
        $meta = self::ENTITIES[$entity];

        $dimension = $config['dimension'] ?? null;
        abort_unless($dimension && in_array($dimension, self::DIMENSIONS, true), 422, 'Unknown dimension.');

        $measure = $config['measure'] ?? 'count';
        abort_unless(isset(self::MEASURES[$measure]), 422, 'Unknown measure.');

        $query = DB::table($meta['table']);
        foreach (($config['joins'] ?? []) as $joinKey) {
            abort_unless(isset(self::JOINS[$joinKey]), 422, "Unknown join {$joinKey}.");
            $j = self::JOINS[$joinKey];
            $query->join($j['table'], DB::raw($j['on']), '=', DB::raw('1=1'));
        }

        $query->whereRaw($meta['tenant'] . ' = ?', [$tenantId])
              ->select(DB::raw("{$dimension} AS label"), DB::raw(self::MEASURES[$measure] . ' AS value'))
              ->groupBy(DB::raw($dimension))
              ->orderByDesc(DB::raw('value'))
              ->limit(30);

        $rows = $query->get();

        return [
            'labels'   => $rows->pluck('label')->map(fn ($v) => (string) ($v ?? '—'))->all(),
            'datasets' => [[
                'label' => "{$entity} · {$measure} by {$dimension}",
                'data'  => $rows->pluck('value')->map(fn ($v) => (int) $v)->all(),
            ]],
            'row_count' => $rows->count(),
        ];
    }

    /** Exposed whitelist for UI. */
    public function schema(): array
    {
        return [
            'entities'   => array_keys(self::ENTITIES),
            'joins'      => array_keys(self::JOINS),
            'dimensions' => self::DIMENSIONS,
            'measures'   => array_keys(self::MEASURES),
        ];
    }
}
