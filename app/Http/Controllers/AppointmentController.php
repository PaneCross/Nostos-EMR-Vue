<?php

// ─── AppointmentController ────────────────────────────────────────────────────
// Participant appointment scheduling across all PACE service types.
//
// Routes (nested under /participants/{participant}, behind auth middleware):
//   GET    /participants/{participant}/appointments              → index()
//   POST   /participants/{participant}/appointments              → store()
//   GET    /participants/{participant}/appointments/{appointment}→ show()
//   PUT    /participants/{participant}/appointments/{appointment}→ update()
//   PATCH  /participants/{participant}/appointments/{id}/confirm → confirm()
//   PATCH  /participants/{participant}/appointments/{id}/complete→ complete()
//   PATCH  /participants/{participant}/appointments/{id}/cancel  → cancel()
//   PATCH  /participants/{participant}/appointments/{id}/no-show → noShow()
//
// Also: GET /schedule → Schedule/Index Inertia page (calendar view)
//
// Conflict detection:
//   ConflictDetectionService checks for participant time-overlap (409 if conflict).
//   Transport conflict (2-hour window) checked additionally when transport_required.
//
// Permission model:
//   All authenticated users in the tenant can view.
//   All clinical staff can create/update.
//   Cancellation requires a reason field.
//   No-show fires an audit log event (Phase 4 alert integration deferred to 5B).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Participant;
use App\Services\AlertService;
use App\Services\ConflictDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function __construct(private ConflictDetectionService $conflictService) {}

    // ── Calendar page ─────────────────────────────────────────────────────────

    /**
     * GET /schedule
     * Inertia: department calendar view. Passes appointment types and locations
     * as props; actual appointment data loaded via the index() JSON endpoint.
     */
    public function calendarPage(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Schedule/Index', [
            'appointmentTypes' => Appointment::APPOINTMENT_TYPES,
            'typeLabels'       => Appointment::TYPE_LABELS,
            'typeColors'       => Appointment::TYPE_COLORS,
            'locations'        => Location::forTenant($user->tenant_id)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'location_type', 'site_id', 'city']),
        ]);
    }

    // ── Appointment listing ───────────────────────────────────────────────────

    /**
     * GET /participants/{participant}/appointments
     * Returns paginated appointments for a participant. Supports date range
     * filters for the calendar view (start_date, end_date query params).
     * Also used by the department calendar (provider_id filter).
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $query = $participant->appointments()
            ->with(['provider:id,first_name,last_name', 'location:id,name,location_type'])
            ->orderBy('scheduled_start');

        if ($start = $request->input('start_date')) {
            $query->where('scheduled_start', '>=', $start);
        }
        if ($end = $request->input('end_date')) {
            $query->where('scheduled_start', '<=', $end);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(50));
    }

    /**
     * GET /schedule/appointments
     * Returns all appointments for the tenant in a date range (calendar view).
     * Used by the department-wide calendar (not nested under a participant).
     */
    public function calendarAppointments(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Appointment::forTenant($user->tenant_id)
            ->with([
                'participant:id,first_name,last_name,mrn',
                'provider:id,first_name,last_name',
                'location:id,name',
            ])
            ->orderBy('scheduled_start');

        if ($start = $request->input('start_date')) {
            $query->where('scheduled_start', '>=', $start);
        }
        if ($end = $request->input('end_date')) {
            $query->where('scheduled_start', '<=', $end);
        }
        if ($providerId = $request->input('provider_id')) {
            $query->forProvider((int) $providerId);
        }
        if ($type = $request->input('type')) {
            $query->where('appointment_type', $type);
        }

        return response()->json($query->get());
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    /**
     * GET /participants/{participant}/appointments/{appointment}
     */
    public function show(Request $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        return response()->json(
            $appointment->load(['provider:id,first_name,last_name', 'location', 'createdBy:id,first_name,last_name'])
        );
    }

    /**
     * Phase 14.2 : standalone appointment detail Inertia page.
     * GET /appointments/{appointment}
     *
     * Renders the full detail view independent of a participant route context,
     * so dashboard widgets, global search, and scheduler can link straight
     * into the record.
     */
    public function showStandalone(Request $request, Appointment $appointment)
    {
        $user = $request->user();
        abort_if(!$user, 401);
        abort_unless($appointment->tenant_id === $user->tenant_id, 404);

        $appointment->load([
            'participant:id,first_name,last_name,mrn,dob,tenant_id,site_id',
            'provider:id,first_name,last_name,department',
            'location',
            'createdBy:id,first_name,last_name',
            'site:id,name',
        ]);

        $canEdit = $user->isSuperAdmin() || in_array($user->department, [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'activities', 'home_care', 'it_admin', 'enrollment',
        ], true);

        return \Inertia\Inertia::render('Appointments/Show', [
            'appointment' => $appointment,
            'canEdit'     => $canEdit,
        ]);
    }

    /**
     * POST /participants/{participant}/appointments
     * Creates a new appointment after checking for participant time conflicts.
     * Returns 409 if a conflict exists.
     */
    public function store(StoreAppointmentRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $data  = $request->validated();
        $start = \Carbon\Carbon::parse($data['scheduled_start']);
        $end   = \Carbon\Carbon::parse($data['scheduled_end']);

        // ── Cross-site guard ─────────────────────────────────────────────────
        // If the chosen location has a site_id different from the participant's
        // enrolled site, require explicit client confirmation. Prevents silent
        // cross-site bookings.
        $crossSite     = null;
        $crossSiteName = null;
        if (! empty($data['location_id'])) {
            $loc = Location::find($data['location_id']);
            if ($loc && $loc->site_id && $loc->site_id !== $participant->site_id) {
                if (empty($data['cross_site_confirmed'])) {
                    return response()->json([
                        'message' => 'Cross-site appointment requires confirmation. Please confirm in the UI.',
                        'error'   => 'cross_site_unconfirmed',
                    ], 422);
                }
                $crossSite     = $loc;
                $crossSiteName = $loc->site?->name ?? "Site {$loc->site_id}";
            }
        }

        // Strip the confirmation flag : it's not an Appointment column.
        unset($data['cross_site_confirmed']);

        // ── Conflict check: participant cannot have overlapping appointments ──
        if ($this->conflictService->checkParticipantConflict($participant->id, $start, $end)) {
            return response()->json([
                'message' => 'Scheduling conflict: the participant already has an appointment during this time.',
                'error'   => 'conflict',
            ], 409);
        }

        // ── Transport window check: 2-hour buffer between transport trips ─────
        if (! empty($data['transport_required'])) {
            if ($this->conflictService->checkTransportConflict($participant->id, $start)) {
                return response()->json([
                    'message' => 'Transport conflict: another transport-required appointment is within 2 hours.',
                    'error'   => 'transport_conflict',
                ], 409);
            }
        }

        $appointment = Appointment::create(array_merge($data, [
            'participant_id'     => $participant->id,
            'tenant_id'          => $user->tenant_id,
            'site_id'            => $participant->site_id,
            'status'             => $data['status'] ?? 'scheduled',
            'created_by_user_id' => $user->id,
        ]));

        AuditLog::record(
            action:       'appointment.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Appointment ({$appointment->appointment_type}) created for {$participant->mrn} at {$start->format('Y-m-d H:i')}",
            newValues:    $data,
        );

        // Cross-site audit entry : surfaces on the participant's audit tab so
        // home-site staff can see the participant is going elsewhere that day.
        if ($crossSite) {
            AuditLog::record(
                action:       'appointment.cross_site_scheduled',
                tenantId:     $user->tenant_id,
                userId:       $user->id,
                resourceType: 'participant',
                resourceId:   $participant->id,
                description:  "Cross-site appointment scheduled at {$crossSiteName} ({$appointment->appointment_type}, {$start->format('Y-m-d H:i')})",
                newValues:    [
                    'appointment_id'  => $appointment->id,
                    'home_site_id'    => $participant->site_id,
                    'host_site_id'    => $crossSite->site_id,
                    'host_site_name'  => $crossSiteName,
                    'location_id'     => $crossSite->id,
                    'location_name'   => $crossSite->name,
                    'scheduled_start' => $start->toIso8601String(),
                ],
            );
        }

        return response()->json(
            $appointment->load(['provider:id,first_name,last_name', 'location:id,name']),
            201
        );
    }

    /**
     * PUT /participants/{participant}/appointments/{appointment}
     * Updates scheduling details. Only editable if status is scheduled/confirmed.
     * Re-checks conflict detection when time is changed.
     */
    public function update(UpdateAppointmentRequest $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        abort_unless($appointment->isEditable(), 422, 'Only scheduled or confirmed appointments can be updated.');

        $data  = $request->validated();
        $start = \Carbon\Carbon::parse($data['scheduled_start'] ?? $appointment->scheduled_start);
        $end   = \Carbon\Carbon::parse($data['scheduled_end']   ?? $appointment->scheduled_end);

        // ── Re-check conflict, excluding this appointment itself ───────────────
        if ($this->conflictService->checkParticipantConflict($participant->id, $start, $end, $appointment->id)) {
            return response()->json([
                'message' => 'Scheduling conflict: the participant already has an appointment during this time.',
                'error'   => 'conflict',
            ], 409);
        }

        $transportRequired = $data['transport_required'] ?? $appointment->transport_required;
        if ($transportRequired) {
            if ($this->conflictService->checkTransportConflict($participant->id, $start, $appointment->id)) {
                return response()->json([
                    'message' => 'Transport conflict: another transport-required appointment is within 2 hours.',
                    'error'   => 'transport_conflict',
                ], 409);
            }
        }

        $old = $appointment->only(array_keys($data));
        $appointment->update($data);

        AuditLog::record(
            action:       'appointment.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Appointment ({$appointment->appointment_type}) updated for {$participant->mrn}",
            oldValues:    $old,
            newValues:    $data,
        );

        return response()->json($appointment->fresh()->load(['provider:id,first_name,last_name', 'location:id,name']));
    }

    // ── Status transitions ─────────────────────────────────────────────────────

    /**
     * PATCH /participants/{participant}/appointments/{appointment}/confirm
     */
    public function confirm(Request $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        abort_unless($appointment->status === 'scheduled', 422, 'Only scheduled appointments can be confirmed.');

        $appointment->update(['status' => 'confirmed']);

        AuditLog::record(
            action:       'appointment.confirmed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Appointment ({$appointment->appointment_type}) confirmed for {$participant->mrn}",
        );

        return response()->json($appointment->fresh());
    }

    /**
     * PATCH /participants/{participant}/appointments/{appointment}/complete
     */
    public function complete(Request $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        abort_unless($appointment->isEditable(), 422, 'Only scheduled or confirmed appointments can be completed.');

        $appointment->complete();

        AuditLog::record(
            action:       'appointment.completed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Appointment ({$appointment->appointment_type}) marked complete for {$participant->mrn}",
        );

        return response()->json($appointment->fresh());
    }

    /**
     * PATCH /participants/{participant}/appointments/{appointment}/cancel
     * Cancellation reason is required (enforced in request validation).
     */
    public function cancel(Request $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        abort_unless($appointment->isEditable(), 422, 'Only scheduled or confirmed appointments can be cancelled.');

        $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        $appointment->cancel($request->input('cancellation_reason'));

        AuditLog::record(
            action:       'appointment.cancelled',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Appointment ({$appointment->appointment_type}) cancelled for {$participant->mrn}: {$request->input('cancellation_reason')}",
        );

        return response()->json($appointment->fresh());
    }

    /**
     * PATCH /participants/{participant}/appointments/{appointment}/no-show
     */
    public function noShow(Request $request, Participant $participant, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeForParticipant($appointment, $participant);

        abort_unless($appointment->isEditable(), 422, 'Only scheduled or confirmed appointments can be marked no-show.');

        $appointment->noShow();

        AuditLog::record(
            action:       'appointment.no_show',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'appointment',
            resourceId:   $appointment->id,
            description:  "Participant {$participant->mrn} marked no-show for {$appointment->appointment_type} appointment",
        );

        // Phase Q7 : alert to transportation + scheduling on no-show.
        app(AlertService::class)->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'alert_type'         => 'appointment_no_show',
            'severity'           => 'info',
            'title'              => 'Appointment no-show',
            'message'            => "Participant {$participant->first_name} {$participant->last_name} (MRN {$participant->mrn}) was a no-show for a {$appointment->appointment_type} appointment on " . $appointment->scheduled_start->format('M j, Y g:i A') . '. Follow up to reschedule and confirm transportation.',
            'target_departments' => ['transportation', 'enrollment'],
            'source_module'      => 'scheduling',
            'metadata'           => ['appointment_id' => $appointment->id, 'appointment_type' => $appointment->appointment_type],
            'created_by_system'  => true,
        ]);

        // Phase W2-tier1: optional PCP copy per Org Settings preference. The
        // transportation/enrollment alert above fires regardless; this is an
        // additional named-recipient layer when the org has opted in.
        $prefs = app(\App\Services\NotificationPreferenceService::class);
        if ($prefs->shouldNotify($user->tenant_id, 'workflow.appointment_no_show.notify_pcp', $participant->site_id)) {
            $pcpId = $participant->primary_provider_user_id ?? null;
            if ($pcpId) {
                app(AlertService::class)->create([
                    'tenant_id'          => $user->tenant_id,
                    'participant_id'     => $participant->id,
                    'alert_type'         => 'appointment_no_show_pcp_copy',
                    'severity'           => 'info',
                    'title'              => 'Appointment no-show (PCP copy)',
                    'message'            => "{$participant->first_name} {$participant->last_name} missed their {$appointment->appointment_type} appointment on " . $appointment->scheduled_start->format('M j, Y g:i A') . '.',
                    'target_departments' => ['primary_care'],
                    'source_module'      => 'scheduling',
                    'metadata'           => ['appointment_id' => $appointment->id, 'pcp_user_id' => $pcpId],
                    'created_by_system'  => true,
                ]);
            }
        }

        return response()->json($appointment->fresh());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    private function authorizeForParticipant(Appointment $appointment, Participant $participant): void
    {
        abort_if($appointment->participant_id !== $participant->id, 404);
    }
}
