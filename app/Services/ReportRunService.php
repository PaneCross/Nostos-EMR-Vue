<?php

// ─── ReportRunService ────────────────────────────────────────────────────────
// Phase 15.3. Translates a ReportDefinition (entity + filters + columns) into
// a safe, tenant-scoped query and returns rows. Filter operators are a small
// allow-list so user input can't inject SQL.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Appointment;
use App\Models\CarePlan;
use App\Models\Grievance;
use App\Models\IncidentReport;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

class ReportRunService
{
    private const OPS = ['=', '!=', '<', '<=', '>', '>=', 'like', 'in', 'is_null', 'not_null'];

    private const FIELD_ALLOWLIST = [
        'participants' => ['id', 'mrn', 'first_name', 'last_name', 'dob', 'gender',
            'enrollment_status', 'enrollment_date', 'disenrollment_date',
            'primary_language', 'site_id', 'tenant_id'],
        'medications'  => ['id', 'participant_id', 'drug_name', 'rxnorm_code', 'status',
            'is_prn', 'is_controlled', 'controlled_schedule',
            'prescribed_date', 'start_date', 'end_date', 'tenant_id'],
        'grievances'   => ['id', 'participant_id', 'category', 'priority', 'status',
            'filed_at', 'resolution_date', 'cms_reportable', 'tenant_id'],
        'appointments' => ['id', 'participant_id', 'provider_user_id', 'appointment_type',
            'status', 'scheduled_start', 'scheduled_end', 'tenant_id'],
        'incidents'    => ['id', 'participant_id', 'incident_type', 'severity',
            'occurred_at', 'status', 'tenant_id'],
        'care_plans'   => ['id', 'participant_id', 'version', 'status', 'effective_date',
            'review_due_date', 'tenant_id'],
    ];

    /** Run a report and return raw rows. Cap 5000 rows. */
    public function run(ReportDefinition $definition): array
    {
        $builder = $this->builderFor($definition->entity);
        $builder = $builder->where($this->tenantColumn($definition->entity), $definition->tenant_id);

        $columns = $this->allowedColumns($definition->entity, $definition->columns ?: ['*']);

        foreach (($definition->filters ?? []) as $filter) {
            $this->applyFilter($builder, $definition->entity, $filter);
        }

        $rows = $builder->limit(5000)->get($columns);

        $definition->update(['last_run_at' => now()]);

        return [
            'columns' => $columns,
            'rows'    => $rows->map(fn ($m) => $m->only($columns === ['*']
                ? array_keys($m->getAttributes())
                : $columns))->all(),
            'total'   => $rows->count(),
        ];
    }

    /** Stream rows as CSV. */
    public function toCsv(ReportDefinition $definition): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $result = $this->run($definition);
        $columns = $result['columns'] === ['*']
            ? array_keys($result['rows'][0] ?? [])
            : $result['columns'];

        return response()->streamDownload(function () use ($result, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns));
            }
            fclose($out);
        }, 'report-' . $definition->id . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function builderFor(string $entity): Builder
    {
        return match ($entity) {
            'participants' => Participant::query(),
            'medications'  => Medication::query(),
            'grievances'   => Grievance::query(),
            'appointments' => Appointment::query(),
            'incidents'    => IncidentReport::query(),
            'care_plans'   => CarePlan::query(),
            default        => throw new \InvalidArgumentException("Unknown entity: {$entity}"),
        };
    }

    private function tenantColumn(string $entity): string
    {
        // All EMR tables carry tenant_id directly.
        return 'tenant_id';
    }

    private function allowedColumns(string $entity, array $requested): array
    {
        if ($requested === ['*']) return ['*'];
        $allow = self::FIELD_ALLOWLIST[$entity] ?? [];
        return array_values(array_intersect($requested, $allow));
    }

    private function applyFilter(Builder $b, string $entity, array $f): void
    {
        $field = $f['field'] ?? null;
        $op    = $f['op']    ?? '=';
        $value = $f['value'] ?? null;

        $allow = self::FIELD_ALLOWLIST[$entity] ?? [];
        if (! in_array($field, $allow, true)) return;
        if (! in_array($op, self::OPS, true)) return;

        switch ($op) {
            case 'like':     $b->where($field, 'ilike', '%' . $value . '%'); break;
            case 'in':       $b->whereIn($field, (array) $value); break;
            case 'is_null':  $b->whereNull($field); break;
            case 'not_null': $b->whereNotNull($field); break;
            default:         $b->where($field, $op, $value);
        }
    }
}
