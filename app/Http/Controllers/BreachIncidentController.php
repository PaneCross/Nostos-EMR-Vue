<?php

// ─── BreachIncidentController — Phase P4 ────────────────────────────────────
namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\Participant;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BreachIncidentController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['it_admin', 'qa_compliance', 'executive'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        $rows = BreachIncident::forTenant($u->tenant_id)
            ->orderByDesc('discovered_at')
            ->with('loggedBy:id,first_name,last_name')
            ->get();
        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('ItAdmin/BreachIncidents', ['incidents' => $rows]);
        }
        return response()->json(['incidents' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'discovered_at'   => 'required|date',
            'occurred_at'     => 'nullable|date|before_or_equal:discovered_at',
            'affected_count'  => 'required|integer|min:1',
            'breach_type'     => 'required|in:' . implode(',', BreachIncident::TYPES),
            'description'     => 'required|string|min:10|max:8000',
            'root_cause'      => 'nullable|string|max:4000',
            'mitigation_taken'=> 'nullable|string|max:4000',
            'state'           => 'nullable|string|size:2',
        ]);

        $discovered = Carbon::parse($validated['discovered_at']);
        $deadline = BreachIncident::computeHhsDeadline($validated['affected_count'], $discovered);

        $row = BreachIncident::create(array_merge($validated, [
            'tenant_id'        => $u->tenant_id,
            'hhs_deadline_at'  => $deadline,
            'status'           => 'open',
            'logged_by_user_id' => $u->id,
        ]));

        AuditLog::record(
            action: 'breach.incident_logged',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'breach_incident',
            resourceId: $row->id,
            description: "Breach incident logged: {$validated['breach_type']}, {$validated['affected_count']} affected.",
        );

        // Phase SS2 — optional Program Director notification per Org Settings.
        // Hardwired IT Admin / Compliance chain (45 CFR §164.404) is unaffected;
        // this is an additional copy when the org has opted in.
        $prefs = app(NotificationPreferenceService::class);
        if ($prefs->shouldNotify($u->tenant_id, 'designation.program_director.breach_incident_logged')) {
            $director = User::where('tenant_id', $u->tenant_id)
                ->withDesignation('program_director')
                ->where('is_active', true)
                ->first();
            if ($director) {
                Alert::create([
                    'tenant_id'          => $u->tenant_id,
                    'alert_type'         => 'breach_incident_logged',
                    'title'              => "HIPAA Breach Logged — Incident #{$row->id}",
                    'message'            => "HIPAA breach incident logged: {$validated['breach_type']}, {$validated['affected_count']} affected. HHS deadline: " . $deadline?->toDateString() . '.',
                    'severity'           => 'critical',
                    'source_module'      => 'security_compliance',
                    'target_departments' => ['executive'],
                    'created_by_system'  => false,
                    'created_by_user_id' => $u->id,
                    'metadata'           => [
                        'breach_incident_id'  => $row->id,
                        'program_director_id' => $director->id,
                    ],
                ]);
            }
        }

        return response()->json(['incident' => $row], 201);
    }

    public function markIndividualsNotified(Request $request, BreachIncident $breachIncident): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($breachIncident->tenant_id !== $u->tenant_id, 403);
        $breachIncident->update([
            'individual_notification_sent_at' => now(),
            'status' => 'individuals_notified',
        ]);
        AuditLog::record(
            action: 'breach.individuals_notified',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'breach_incident', resourceId: $breachIncident->id,
            description: 'Individual breach notification sent.',
        );
        return response()->json(['incident' => $breachIncident->fresh()]);
    }

    public function markHhsNotified(Request $request, BreachIncident $breachIncident): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($breachIncident->tenant_id !== $u->tenant_id, 403);
        $breachIncident->update([
            'hhs_notified_at' => now(),
            'status' => 'hhs_notified',
        ]);
        AuditLog::record(
            action: 'breach.hhs_notified',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'breach_incident', resourceId: $breachIncident->id,
            description: 'HHS breach notification submitted.',
        );
        return response()->json(['incident' => $breachIncident->fresh()]);
    }

    // Phase Q1 — generate HIPAA §164.404 individual notification letter PDF
    public function generateLetter(Request $request, BreachIncident $breachIncident, Participant $participant): Response
    {
        $this->gate();
        $u = Auth::user();
        abort_if($breachIncident->tenant_id !== $u->tenant_id, 403);
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $tenant = $u->tenant;
        $address = $participant->addresses()->where('is_primary', true)->first()
            ?? $participant->addresses()->first();
        $addressLine = $address
            ? trim(($address->street ?? '') . ', ' . ($address->city ?? '') . ', ' . ($address->state ?? '') . ' ' . ($address->zip ?? ''), ', ')
            : null;

        $pdf = Pdf::loadView('pdfs.breach_notification_letter', [
            'breach'         => $breachIncident,
            'participant'    => $participant,
            'address'        => $addressLine,
            'tenant_name'    => $tenant?->name,
            'tenant_address' => $tenant?->address ?? null,
            'tenant_phone'   => $tenant?->phone ?? null,
            'signer_name'    => $u->first_name . ' ' . $u->last_name,
            'signer_title'   => 'Privacy Officer',
        ])->setPaper('letter', 'portrait');

        AuditLog::record(
            action: 'breach.letter_generated',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'breach_incident', resourceId: $breachIncident->id,
            description: "Breach notification letter generated for participant #{$participant->id}.",
        );

        return $pdf->stream("breach-notification-{$breachIncident->id}-participant-{$participant->id}.pdf");
    }
}
