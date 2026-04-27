<?php

// ─── VitalThreshold ──────────────────────────────────────────────────────────
// Phase B6. Per-tenant override of national-default vital threshold ranges.
// Evaluator uses tenant row if present, else falls back to DEFAULTS constant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VitalThreshold extends Model
{
    protected $table = 'emr_vital_thresholds';

    public const FIELDS = [
        'bp_systolic', 'bp_diastolic', 'pulse', 'respiratory_rate',
        'temperature_f', 'o2_saturation', 'blood_glucose',
    ];

    /**
     * National default thresholds for elderly/PACE population.
     * warning_* + critical_* may be null when a direction isn't clinically meaningful.
     * Units: BP mmHg, pulse/RR bpm, temp °F, SpO2 %, glucose mg/dL.
     */
    public const DEFAULTS = [
        'bp_systolic'      => ['warning_low' => 90,  'warning_high' => 160, 'critical_low' => 80,  'critical_high' => 180],
        'bp_diastolic'     => ['warning_low' => 60,  'warning_high' => 100, 'critical_low' => 50,  'critical_high' => 120],
        'pulse'            => ['warning_low' => 50,  'warning_high' => 100, 'critical_low' => 40,  'critical_high' => 130],
        'respiratory_rate' => ['warning_low' => 10,  'warning_high' => 22,  'critical_low' => 8,   'critical_high' => 30],
        'temperature_f'    => ['warning_low' => 96.8,'warning_high' => 100.4,'critical_low' => 95, 'critical_high' => 101.5],
        'o2_saturation'    => ['warning_low' => 92,  'warning_high' => null,'critical_low' => 88,  'critical_high' => null],
        'blood_glucose'    => ['warning_low' => 70,  'warning_high' => 250, 'critical_low' => 54,  'critical_high' => 400],
    ];

    protected $fillable = [
        'tenant_id', 'vital_field',
        'warning_low', 'warning_high', 'critical_low', 'critical_high', 'notes',
    ];

    protected $casts = [
        'warning_low'  => 'decimal:2',
        'warning_high' => 'decimal:2',
        'critical_low' => 'decimal:2',
        'critical_high'=> 'decimal:2',
    ];

    /**
     * Resolve thresholds for a (tenant, field) : tenant-override if present,
     * else the hard-coded national default.
     *
     * @return array{warning_low: ?float, warning_high: ?float, critical_low: ?float, critical_high: ?float}
     */
    public static function resolve(int $tenantId, string $field): array
    {
        $row = static::where('tenant_id', $tenantId)->where('vital_field', $field)->first();
        if ($row) {
            return [
                'warning_low'   => $row->warning_low  !== null ? (float) $row->warning_low  : null,
                'warning_high'  => $row->warning_high !== null ? (float) $row->warning_high : null,
                'critical_low'  => $row->critical_low !== null ? (float) $row->critical_low : null,
                'critical_high' => $row->critical_high!== null ? (float) $row->critical_high: null,
            ];
        }
        return self::DEFAULTS[$field] ?? [
            'warning_low' => null, 'warning_high' => null,
            'critical_low' => null, 'critical_high' => null,
        ];
    }
}
