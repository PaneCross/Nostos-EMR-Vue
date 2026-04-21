<?php

// ─── DayCenterAttendanceSeeder ────────────────────────────────────────────────
// Seeds realistic attendance records for the past ~2 weeks so the Day Center
// page, reports, and the summary endpoint have something to show.
//
// Rules:
//   - For each participant with day_center_days set, iterate the past 14 days.
//     On days where the weekday is in the participant's day_center_days, roll
//     an attendance record with a mostly-present / some-late / some-absent mix.
//   - Idempotent: uses upsert by (tenant_id, participant_id, site_id, attendance_date).
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DayCenterAttendanceSeeder extends Seeder
{
    // PostgreSQL weekday short code corresponding to Carbon's dayOfWeek (0=Sun).
    private const WEEKDAY_CODES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function run(): void
    {
        $participants = Participant::where('enrollment_status', 'enrolled')
            ->whereNotNull('day_center_days')
            ->with('site:id,tenant_id')
            ->get();

        if ($participants->isEmpty()) {
            $this->command?->info('  No enrolled participants with day_center_days set — skipping attendance seed.');
            return;
        }

        // Pick a recorder per tenant — activities dept user if available, else any user.
        $recorderByTenant = [];

        $today    = Carbon::today();
        $windowStart = $today->copy()->subDays(14);
        $created  = 0;

        foreach ($participants as $p) {
            $days = is_array($p->day_center_days) ? $p->day_center_days : [];
            if (empty($days)) continue;

            if (! isset($recorderByTenant[$p->tenant_id])) {
                $recorderByTenant[$p->tenant_id] = User::where('tenant_id', $p->tenant_id)
                    ->where('department', 'activities')
                    ->value('id')
                    ?? User::where('tenant_id', $p->tenant_id)->value('id');
            }
            $recorderId = $recorderByTenant[$p->tenant_id];
            if (! $recorderId) continue;

            for ($d = $windowStart->copy(); $d->lte($today); $d->addDay()) {
                $code = self::WEEKDAY_CODES[$d->dayOfWeek];
                if (! in_array($code, $days, true)) continue;

                // Don't create attendance for future dates (window ends at today but
                // respect if "today" hasn't happened operationally — skip today for
                // realism: staff haven't recorded yet).
                if ($d->isSameDay($today)) continue;

                // Attendance mix: 80% present, 10% late, 8% absent, 2% excused.
                $roll = mt_rand(1, 100);
                [$status, $checkIn, $checkOut, $absentReason] = match (true) {
                    $roll <= 80 => ['present', '09:05:00', '15:30:00', null],
                    $roll <= 90 => ['late',    '10:25:00', '15:30:00', null],
                    $roll <= 98 => ['absent',  null,       null,       'illness'],
                    default     => ['excused', null,       null,       'medical_appt'],
                };

                DayCenterAttendance::updateOrCreate(
                    [
                        'tenant_id'       => $p->tenant_id,
                        'participant_id'  => $p->id,
                        'site_id'         => $p->site_id,
                        'attendance_date' => $d->toDateString(),
                    ],
                    [
                        'status'              => $status,
                        'check_in_time'       => $checkIn,
                        'check_out_time'      => $checkOut,
                        'absent_reason'       => $absentReason,
                        'notes'               => null,
                        'recorded_by_user_id' => $recorderId,
                    ],
                );
                $created++;
            }
        }

        $this->command?->info("  Seeded {$created} day-center attendance records across the last 14 days.");
    }
}
