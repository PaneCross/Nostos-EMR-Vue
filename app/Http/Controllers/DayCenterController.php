<?php

// ─── DayCenterController ──────────────────────────────────────────────────────
// Manages day center attendance for enrolled PACE participants.
// Access: activities, it_admin, super_admin (other depts can view)
//
// Routes:
//   GET  /scheduling/day-center            : attendance page (Inertia)
//   GET  /scheduling/day-center/roster     : JSON: enrolled participants for a date/site
//   POST /scheduling/day-center/check-in   : mark participant present / check-in time
//   POST /scheduling/day-center/absent     : mark participant absent with reason
//   GET  /scheduling/day-center/summary    : JSON: attendance summary counts for date range
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\AuditLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DayCenterController extends Controller
{
    /**
     * GET /scheduling/day-center
     * Inertia page: day center attendance management.
     * Defaults to today's date and the user's site.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $date = $request->query('date', now()->toDateString());
        $siteId = $request->query('site_id', $user->site_id);

        // Load today's attendance records for this site
        $attendance = DayCenterAttendance::forTenant($user->tenant_id)
            ->forDate($date)
            ->forSite($siteId)
            ->with('participant:id,mrn,first_name,last_name,preferred_name')
            ->orderBy('check_in_time')
            ->get();

        // Summary counts for the selected date
        $summary = [
            'total'   => $attendance->count(),
            'present' => $attendance->whereIn('status', ['present', 'late'])->count(),
            'absent'  => $attendance->where('status', 'absent')->count(),
            'excused' => $attendance->where('status', 'excused')->count(),
            'late'    => $attendance->where('status', 'late')->count(),
        ];

        return Inertia::render('Scheduling/DayCenter', [
            'attendance'   => $attendance,
            'summary'      => $summary,
            'selectedDate' => $date,
            'selectedSite' => $siteId,
            'statusLabels' => DayCenterAttendance::STATUS_LABELS,
            'absentReasons'=> DayCenterAttendance::ABSENT_REASONS,
            'canManage'    => in_array($user->department, ['activities', 'it_admin', 'super_admin'])
                              || ($user->role === 'super_admin'),
        ]);
    }

    /**
     * GET /scheduling/day-center/roster
     * JSON: paginated enrolled participants for a given date/site,
     * merged with any existing attendance records for that day.
     */
    public function roster(Request $request): JsonResponse
    {
        $user   = $request->user();
        $date   = $request->query('date', now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);

        // Weekday code for the selected date (mon, tue, wed, ...)
        $weekday = strtolower(substr(Carbon::parse($date)->format('D'), 0, 3));

        // ── Home-site enrolled participants ──────────────────────────────────
        $homeParticipants = Participant::where('tenant_id', $user->tenant_id)
            ->where('site_id', $siteId)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->select('id', 'site_id', 'mrn', 'first_name', 'last_name', 'preferred_name', 'day_center_days')
            ->orderBy('last_name')
            ->get();

        // ── Home-site day_center_attendance appointments for this date ───────
        // (Any location : overrides recurring pattern.)
        $homeApptIds = Appointment::where('tenant_id', $user->tenant_id)
            ->where('appointment_type', 'day_center_attendance')
            ->whereDate('scheduled_start', $date)
            ->whereNotIn('status', ['cancelled'])
            ->pluck('participant_id')
            ->unique()
            ->toArray();

        // ── Cross-site visitors: appointments whose LOCATION belongs to this site
        // This pulls in participants enrolled at OTHER sites who are coming here.
        $crossSiteApptParticipants = Appointment::where('emr_appointments.tenant_id', $user->tenant_id)
            ->where('appointment_type', 'day_center_attendance')
            ->whereDate('scheduled_start', $date)
            ->whereNotIn('emr_appointments.status', ['cancelled'])
            ->join('emr_locations', 'emr_locations.id', '=', 'emr_appointments.location_id')
            ->where('emr_locations.site_id', $siteId)
            ->join('emr_participants', 'emr_participants.id', '=', 'emr_appointments.participant_id')
            ->where('emr_participants.site_id', '!=', $siteId)
            ->select(
                'emr_participants.id', 'emr_participants.site_id', 'emr_participants.mrn',
                'emr_participants.first_name', 'emr_participants.last_name',
                'emr_participants.preferred_name', 'emr_participants.day_center_days',
            )
            ->distinct()
            ->get();

        // ── Existing attendance records for the host site + date ─────────────
        $records = DayCenterAttendance::forTenant($user->tenant_id)
            ->forDate($date)
            ->forSite($siteId)
            ->pluck('status', 'participant_id')
            ->toArray();

        // Home-site roster: recurring schedule OR appointment override OR already recorded
        $homeRoster = $homeParticipants->filter(function ($p) use ($weekday, $homeApptIds, $records) {
            $scheduled = is_array($p->day_center_days) && in_array($weekday, $p->day_center_days, true);
            $hasAppt   = in_array($p->id, $homeApptIds, true);
            $hasRecord = isset($records[$p->id]);
            return $scheduled || $hasAppt || $hasRecord;
        })->map(function ($p) use ($weekday, $homeApptIds, $records) {
            $scheduled = is_array($p->day_center_days) && in_array($weekday, $p->day_center_days, true);
            $hasAppt   = in_array($p->id, $homeApptIds, true);

            $source = match (true) {
                $scheduled => 'scheduled',
                $hasAppt   => 'appointment',
                default    => 'override',
            };

            return [
                'id'             => $p->id,
                'mrn'            => $p->mrn,
                'name'           => "{$p->last_name}, {$p->first_name}",
                'preferred_name' => $p->preferred_name,
                'attendance'     => $records[$p->id] ?? null,
                'source'         => $source,
                'home_site'      => null, // Home-site : no chip needed
            ];
        });

        // Cache home sites for cross-site visitors so we can show "Home: X" chip
        $crossSiteIds = $crossSiteApptParticipants->pluck('site_id')->unique()->toArray();
        $sitesById = Site::whereIn('id', $crossSiteIds)->pluck('name', 'id')->toArray();

        // Cross-site visitor rows : participants at OTHER sites with appts at THIS site
        $crossRoster = $crossSiteApptParticipants->map(function ($p) use ($records, $sitesById) {
            return [
                'id'             => $p->id,
                'mrn'            => $p->mrn,
                'name'           => "{$p->last_name}, {$p->first_name}",
                'preferred_name' => $p->preferred_name,
                'attendance'     => $records[$p->id] ?? null,
                'source'         => 'cross_site',
                'home_site'      => [
                    'id'   => $p->site_id,
                    'name' => $sitesById[$p->site_id] ?? "Site {$p->site_id}",
                ],
            ];
        });

        // Union, de-dup by participant id (cross-site wins over home if any
        // conflict : shouldn't happen since cross-site filters by site_id != host).
        $roster = $homeRoster
            ->concat($crossRoster)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return response()->json(['roster' => $roster, 'weekday' => $weekday]);
    }

    /**
     * POST /scheduling/day-center/check-in
     * Mark participant present (or late). Creates or updates attendance record.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:emr_participants,id'],
            'site_id'        => ['required', 'integer', 'exists:shared_sites,id'],
            'attendance_date'=> ['required', 'date'],
            'check_in_time'  => ['nullable', 'date_format:H:i'],
            'status'         => ['required', 'in:present,late'],
        ]);

        $this->verifyParticipantTenant($validated['participant_id'], $user->tenant_id);

        $attendance = DayCenterAttendance::updateOrCreate(
            [
                'tenant_id'       => $user->tenant_id,
                'participant_id'  => $validated['participant_id'],
                'site_id'         => $validated['site_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'status'              => $validated['status'],
                'check_in_time'       => $validated['check_in_time'] ?? now()->format('H:i:s'),
                'absent_reason'       => null,
                'recorded_by_user_id' => $user->id,
            ]
        );

        AuditLog::record(
            action: 'day_center.check_in',
            resourceType: 'DayCenterAttendance',
            resourceId: $attendance->id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: ['status' => $validated['status'], 'date' => $validated['attendance_date']],
        );

        $this->recordCrossSiteAttendanceAuditIfApplicable($validated['participant_id'], $validated['site_id'], $validated['attendance_date'], $validated['status'], $user);

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * POST /scheduling/day-center/absent
     * Mark participant absent or excused with a reason.
     */
    public function markAbsent(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:emr_participants,id'],
            'site_id'        => ['required', 'integer', 'exists:shared_sites,id'],
            'attendance_date'=> ['required', 'date'],
            'status'         => ['required', 'in:absent,excused'],
            'absent_reason'  => ['required', 'string', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $this->verifyParticipantTenant($validated['participant_id'], $user->tenant_id);

        $attendance = DayCenterAttendance::updateOrCreate(
            [
                'tenant_id'       => $user->tenant_id,
                'participant_id'  => $validated['participant_id'],
                'site_id'         => $validated['site_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'status'              => $validated['status'],
                'check_in_time'       => null,
                'check_out_time'      => null,
                'absent_reason'       => $validated['absent_reason'],
                'notes'               => $validated['notes'] ?? null,
                'recorded_by_user_id' => $user->id,
            ]
        );

        AuditLog::record(
            action: 'day_center.absent',
            resourceType: 'DayCenterAttendance',
            resourceId: $attendance->id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: ['status' => $validated['status'], 'reason' => $validated['absent_reason']],
        );

        // Phase SS2 : workflow preference: notify Social Work on day-center no-shows.
        // Default ON. Tenants can disable via Org Settings if their workflow uses
        // a different recipient (e.g. an activities coordinator handles outreach).
        $prefs = app(\App\Services\NotificationPreferenceService::class);
        if ($prefs->shouldNotify($user->tenant_id, 'workflow.day_center_no_show.notify_social_work')) {
            \App\Models\Alert::create([
                'tenant_id'          => $user->tenant_id,
                'participant_id'     => $validated['participant_id'],
                'alert_type'         => 'day_center_absence',
                'title'              => 'Day-center no-show',
                'message'            => 'Participant marked absent from day center on ' . $validated['attendance_date'] . ($validated['absent_reason'] ? " - reason: {$validated['absent_reason']}" : ''),
                'severity'           => 'info',
                'source_module'      => 'day_center',
                'target_departments' => ['social_work'],
                'created_by_system'  => false,
                'created_by_user_id' => $user->id,
                'metadata'           => ['attendance_id' => $attendance->id],
            ]);
        }

        $this->recordCrossSiteAttendanceAuditIfApplicable($validated['participant_id'], $validated['site_id'], $validated['attendance_date'], $validated['status'], $user);

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * Record a participant-scoped audit entry when attendance is recorded at a
     * site different from the participant's enrolled home site. Visible in the
     * participant's Audit tab so home-site staff can see cross-site activity.
     */
    private function recordCrossSiteAttendanceAuditIfApplicable(int $participantId, int $attendedSiteId, string $date, string $status, mixed $user): void
    {
        $participant = Participant::find($participantId);
        if (! $participant || $participant->site_id === $attendedSiteId) {
            return;
        }

        $homeSite = Site::find($participant->site_id);
        $hostSite = Site::find($attendedSiteId);

        AuditLog::record(
            action:       'participant.cross_site_attendance',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  sprintf('Attended at %s (home: %s) on %s : status: %s',
                $hostSite?->name ?? "Site {$attendedSiteId}",
                $homeSite?->name ?? "Site {$participant->site_id}",
                $date,
                $status,
            ),
            newValues: [
                'date'             => $date,
                'status'           => $status,
                'home_site_id'     => $participant->site_id,
                'home_site_name'   => $homeSite?->name,
                'host_site_id'     => $attendedSiteId,
                'host_site_name'   => $hostSite?->name,
            ],
        );
    }

    /**
     * GET /scheduling/day-center/summary
     * JSON: attendance counts per day for a date range : used for calendar heat-map.
     */
    public function summary(Request $request): JsonResponse
    {
        $user   = $request->user();
        $from   = $request->query('from', now()->startOfMonth()->toDateString());
        $to     = $request->query('to',   now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);

        $rows = DayCenterAttendance::forTenant($user->tenant_id)
            ->forSite($siteId)
            ->whereBetween('attendance_date', [$from, $to])
            ->selectRaw('attendance_date, status, COUNT(*) as count')
            ->groupBy('attendance_date', 'status')
            ->get();

        // Pivot into { date: { present: N, absent: N, ... } }
        $summary = [];
        foreach ($rows as $row) {
            $d = $row->attendance_date->toDateString();
            $summary[$d][$row->status] = $row->count;
        }

        return response()->json(['summary' => $summary]);
    }

    /**
     * Phase R6 : POST /scheduling/day-center/check-out
     * Records check-out time on an existing attendance record.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $validated = $request->validate([
            'participant_id'  => ['required', 'integer', 'exists:emr_participants,id'],
            'site_id'         => ['required', 'integer', 'exists:shared_sites,id'],
            'attendance_date' => ['required', 'date'],
            'check_out_time'  => ['nullable', 'date_format:H:i'],
        ]);
        $this->verifyParticipantTenant($validated['participant_id'], $user->tenant_id);

        $attendance = DayCenterAttendance::where([
            'tenant_id'       => $user->tenant_id,
            'participant_id'  => $validated['participant_id'],
            'site_id'         => $validated['site_id'],
            'attendance_date' => $validated['attendance_date'],
        ])->first();
        abort_if(! $attendance, 404, 'No check-in on file for this participant + date.');

        $attendance->update([
            'check_out_time' => $validated['check_out_time'] ?? now()->format('H:i:s'),
        ]);

        AuditLog::record(
            action: 'day_center.check_out',
            resourceType: 'DayCenterAttendance',
            resourceId: $attendance->id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: ['date' => $validated['attendance_date']],
        );

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * Phase R6 : GET /scheduling/day-center/event-status
     * Live event-status snapshot grouped into the four CareHub-style buckets:
     *   scheduled (no record yet), arrived (checked-in, not checked-out),
     *   checked_out, absent_or_cancelled.
     */
    public function eventStatus(Request $request): JsonResponse
    {
        $user   = $request->user();
        $date   = $request->query('date', now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);
        $weekday = strtolower(substr(Carbon::parse($date)->format('D'), 0, 3));

        // Roster of participants expected today (recurring schedule + appt overrides).
        $expected = Participant::where('tenant_id', $user->tenant_id)
            ->where('site_id', $siteId)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->select('id', 'mrn', 'first_name', 'last_name', 'preferred_name', 'day_center_days')
            ->get()
            ->filter(fn ($p) => is_array($p->day_center_days) && in_array($weekday, $p->day_center_days, true))
            ->keyBy('id');

        // Records for today.
        $records = DayCenterAttendance::forTenant($user->tenant_id)
            ->forDate($date)
            ->forSite($siteId)
            ->get()
            ->keyBy('participant_id');

        $scheduled = [];
        $arrived   = [];
        $checkedOut = [];
        $absentOrCancelled = [];

        foreach ($expected as $pid => $p) {
            $rec = $records->get($pid);
            $row = [
                'participant_id' => $p->id,
                'mrn'            => $p->mrn,
                'name'           => trim(($p->preferred_name ?: $p->first_name) . ' ' . $p->last_name),
                'check_in_time'  => $rec?->check_in_time,
                'check_out_time' => $rec?->check_out_time,
                'status'         => $rec?->status,
                'absent_reason'  => $rec?->absent_reason,
            ];
            if (! $rec) {
                $scheduled[] = $row;
            } elseif (in_array($rec->status, ['absent', 'excused'], true)) {
                $absentOrCancelled[] = $row;
            } elseif ($rec->check_out_time) {
                $checkedOut[] = $row;
            } else {
                $arrived[] = $row;
            }
        }

        return response()->json([
            'date'    => $date,
            'site_id' => $siteId,
            'totals'  => [
                'scheduled'           => count($scheduled),
                'arrived'             => count($arrived),
                'checked_out'         => count($checkedOut),
                'absent_or_cancelled' => count($absentOrCancelled),
                'expected'            => $expected->count(),
            ],
            'scheduled'            => $scheduled,
            'arrived'              => $arrived,
            'checked_out'          => $checkedOut,
            'absent_or_cancelled'  => $absentOrCancelled,
        ]);
    }

    /**
     * Phase R6 : GET /scheduling/day-center/roster.pdf
     * Printable attendance roster (PDF) for a given date + site.
     */
    public function rosterPdf(Request $request)
    {
        $user   = $request->user();
        $date   = $request->query('date', now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);

        $rosterPayload = json_decode($this->roster($request)->getContent(), true);
        $site = Site::find($siteId);
        $tenant = $user->tenant;

        $pdf = Pdf::loadView('pdfs.day_center_roster', [
            'rows'        => $rosterPayload['roster'] ?? [],
            'date'        => $date,
            'site_name'   => $site?->name ?? "Site #{$siteId}",
            'tenant_name' => $tenant?->name ?? '',
            'generated_at'=> now(),
        ])->setPaper('letter', 'portrait');

        return $pdf->stream("day-center-roster-{$siteId}-{$date}.pdf");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function authorizeManage(mixed $user): void
    {
        abort_unless(
            in_array($user->department, ['activities', 'it_admin', 'super_admin'])
            || $user->role === 'super_admin',
            403,
            'Only activities staff can manage day center attendance.'
        );
    }

    private function verifyParticipantTenant(int $participantId, int $tenantId): void
    {
        $participant = Participant::find($participantId);
        abort_if(!$participant || $participant->tenant_id !== $tenantId, 403);
    }
}
