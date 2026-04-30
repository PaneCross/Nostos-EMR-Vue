<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = ['activities', 'therapies', 'primary_care', 'home_care', 'social_work', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($r, $u): void { abort_if($r->tenant_id !== $u->effectiveTenantId(), 403); }

    /** GET /activities?from=YYYY-MM-DD&to=YYYY-MM-DD (defaults to next 7 days). */
    public function index(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $from = $request->query('from', now()->toDateString());
        $to   = $request->query('to', now()->addDays(7)->toDateString());

        $events = ActivityEvent::forTenant($u->effectiveTenantId())
            ->whereBetween('scheduled_at', [$from, $to . ' 23:59:59'])
            ->with('facilitator:id,first_name,last_name')
            ->withCount('attendances')
            ->orderBy('scheduled_at')->get();

        return response()->json(['events' => $events]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'site_id'             => 'nullable|integer|exists:shared_sites,id',
            'title'               => 'required|string|max:200',
            'category'            => 'required|in:' . implode(',', ActivityEvent::CATEGORIES),
            'scheduled_at'        => 'required|date',
            'duration_min'        => 'nullable|integer|min:5|max:600',
            'location'            => 'nullable|string|max:200',
            'facilitator_user_id' => 'nullable|integer|exists:shared_users,id',
            'description'         => 'nullable|string|max:4000',
        ]);

        $event = ActivityEvent::create(array_merge($validated, [
            'tenant_id'   => $u->effectiveTenantId(),
            'duration_min'=> $validated['duration_min'] ?? 60,
        ]));

        AuditLog::record(
            action: 'activity.event_created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'activity_event',
            resourceId: $event->id,
            description: "Activity scheduled: {$event->title}",
        );

        return response()->json(['event' => $event], 201);
    }

    public function recordAttendance(Request $request, ActivityEvent $event): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($event, $u);

        $validated = $request->validate([
            'participant_id'    => 'required|integer|exists:emr_participants,id',
            'attendance_status' => 'required|in:' . implode(',', ActivityAttendance::STATUSES),
            'engagement_level'  => 'nullable|in:' . implode(',', ActivityAttendance::ENGAGEMENT),
            'notes'             => 'nullable|string|max:2000',
        ]);

        // Idempotent : one row per (event, participant).
        $att = ActivityAttendance::updateOrCreate(
            [
                'activity_event_id' => $event->id,
                'participant_id'    => $validated['participant_id'],
            ],
            array_merge($validated, [
                'tenant_id'           => $u->effectiveTenantId(),
                'recorded_by_user_id' => $u->id,
            ]),
        );

        return response()->json(['attendance' => $att], 201);
    }

    /** Engagement trend for a specific participant. */
    public function participantTrend(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $rows = ActivityAttendance::forTenant($u->effectiveTenantId())
            ->where('participant_id', $participant->id)
            ->with('event:id,title,category,scheduled_at')
            ->orderByDesc('created_at')->limit(60)->get();

        $summary = [
            'attendances' => $rows->where('attendance_status', 'attended')->count(),
            'total'       => $rows->count(),
            'high_engagement_pct' => $rows->count() > 0
                ? round(100 * $rows->where('engagement_level', 'high')->count() / $rows->count(), 1)
                : 0,
        ];

        return response()->json(['rows' => $rows, 'summary' => $summary]);
    }
}
