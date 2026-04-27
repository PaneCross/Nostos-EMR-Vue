<?php

// ─── ShortWinsF2Controller ───────────────────────────────────────────────────
// Phase F2 : 8 short wins surfacing prior-phase back-end work + a few new
// lightweight features:
//   1. Beers Criteria flags wrapper (reuses C6 service)
//   2. Quick-order SmartSets (SmartSetService)
//   3. Note-PDF print
//   4. Participant-search filter pills (extends existing GlobalSearch pattern)
//   5. Bulk care-plan signing (single audit row)
//   6. Scheduled-note reminder surface (reads from ScheduledNoteReminderJob
//      output : job handles creation; this returns the queue)
//   7. QR wristband print : whole-center bundle
//   8. Participant timeline view (UNION of notes/orders/vitals/appointments)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Services\BeersCriteriaService;
use App\Services\SmartSetService;
use Barryvdh\DomPDF\Facade\Pdf;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShortWinsF2Controller extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /participants/{p}/beers-flags */
    public function beersFlags(Request $request, Participant $participant, BeersCriteriaService $beers): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        return response()->json(['flags' => $beers->evaluate($participant)]);
    }

    /** POST /participants/{p}/smartsets/{key} */
    public function applySmartSet(Request $request, Participant $participant, string $key, SmartSetService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        abort_unless(in_array($u->department, ['primary_care', 'pharmacy', 'it_admin'], true) || $u->isSuperAdmin(), 403);
        return response()->json(['orders' => $svc->apply($participant, $u, $key)], 201);
    }

    /** GET /notes/{note}/pdf */
    public function notePdf(Request $request, ClinicalNote $note): Response
    {
        $this->gate();
        $u = Auth::user();
        abort_if($note->tenant_id !== $u->tenant_id, 403);
        $note->load('participant:id,mrn,first_name,last_name,dob', 'author:id,first_name,last_name,department', 'signedBy:id,first_name,last_name');

        $pdf = Pdf::loadView('pdfs.clinical-note', ['note' => $note])->setPaper('letter', 'portrait');
        return $pdf->stream("note-{$note->id}.pdf");
    }

    /** GET /search/filters?kind=open_grievance|in_appeal|active_fall_risk|discharged_recently */
    public function searchFilters(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $kind = $request->query('kind');

        $query = Participant::where('tenant_id', $u->tenant_id);

        switch ($kind) {
            case 'open_grievance':
                $query->whereIn('id', function ($q) use ($u) {
                    $q->select('participant_id')->from('emr_grievances')
                        ->where('tenant_id', $u->tenant_id)
                        ->whereNotIn('status', ['resolved', 'closed', 'withdrawn']);
                });
                break;
            case 'in_appeal':
                $query->whereIn('id', function ($q) use ($u) {
                    $q->select('participant_id')->from('emr_appeals')
                        ->where('tenant_id', $u->tenant_id)
                        ->whereIn('status', ['filed', 'under_review', 'pending']);
                });
                break;
            case 'active_fall_risk':
                $query->whereIn('id', function ($q) use ($u) {
                    $q->select('participant_id')->from('emr_incidents')
                        ->where('tenant_id', $u->tenant_id)
                        ->where('incident_type', 'fall')
                        ->where('occurred_at', '>=', now()->subDays(90));
                });
                break;
            case 'discharged_recently':
                $query->whereIn('id', function ($q) use ($u) {
                    $q->select('participant_id')->from('emr_discharge_events')
                        ->where('tenant_id', $u->tenant_id)
                        ->where('discharged_on', '>=', now()->subDays(30));
                });
                break;
            default:
                return response()->json(['error' => 'unknown_kind'], 422);
        }

        $rows = $query->limit(200)->get(['id', 'mrn', 'first_name', 'last_name']);
        return response()->json(['rows' => $rows, 'count' => $rows->count()]);
    }

    /** POST /care-plans/bulk-sign */
    public function bulkSignCarePlans(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless(in_array($u->department, ['primary_care', 'idt'], true) || $u->isSuperAdmin(), 403);

        $validated = $request->validate([
            'care_plan_ids'   => 'required|array|min:1|max:100',
            'care_plan_ids.*' => 'integer|exists:emr_care_plans,id',
        ]);

        $updated = CarePlan::whereIn('id', $validated['care_plan_ids'])
            ->where('tenant_id', $u->tenant_id)
            ->whereNull('approved_at')
            ->update([
                'approved_at'         => now(),
                'approved_by_user_id' => $u->id,
                'status'              => 'active',
            ]);

        AuditLog::record(
            action: 'care_plan.bulk_signed',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'care_plan',
            resourceId: 0,
            description: "Bulk care-plan sign: {$updated} plans signed.",
            newValues: ['care_plan_ids' => $validated['care_plan_ids']],
        );

        return response()->json(['signed_count' => $updated]);
    }

    /** GET /wristbands/center-print.pdf?site_id=X : all active participants at site. */
    public function centerWristbandPdf(Request $request): Response
    {
        $this->gate();
        $u = Auth::user();
        $siteId = (int) $request->query('site_id', $u->site_id ?? 0);
        abort_if($siteId === 0, 422, 'site_id required.');

        $participants = Participant::where('tenant_id', $u->tenant_id)
            ->where('site_id', $siteId)
            ->where('enrollment_status', 'enrolled')
            ->orderBy('last_name')
            ->get();

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(120),
            new SvgImageBackEnd()
        ));
        $items = $participants->map(function ($p) use ($writer) {
            if (! $p->barcode_value) {
                $p->barcode_value = "PT-{$p->tenant_id}-{$p->mrn}";
                $p->save();
            }
            return [
                'participant' => $p,
                'qr_svg'      => $writer->writeString($p->barcode_value),
            ];
        });

        $pdf = Pdf::loadView('pdfs.wristband-center', ['items' => $items])->setPaper('letter', 'portrait');
        return $pdf->stream("center-wristbands-{$siteId}.pdf");
    }

    /** GET /participants/{p}/timeline : merged notes/orders/vitals/appointments. */
    public function timeline(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $notes = ClinicalNote::where('participant_id', $participant->id)
            ->orderByDesc('visit_date')->limit(30)
            ->get(['id', 'note_type', 'visit_date as date', 'status', 'authored_by_user_id'])
            ->map(fn ($n) => ['kind' => 'note', 'date' => $n->date, 'data' => $n]);

        $orders = \App\Models\ClinicalOrder::where('participant_id', $participant->id)
            ->orderByDesc('ordered_at')->limit(30)
            ->get(['id', 'order_type', 'ordered_at as date', 'status'])
            ->map(fn ($o) => ['kind' => 'order', 'date' => $o->date, 'data' => $o]);

        $vitals = \App\Models\Vital::where('participant_id', $participant->id)
            ->orderByDesc('recorded_at')->limit(20)
            ->get(['id', 'recorded_at as date', 'bp_systolic', 'bp_diastolic', 'pulse'])
            ->map(fn ($v) => ['kind' => 'vitals', 'date' => $v->date, 'data' => $v]);

        $appts = \App\Models\Appointment::where('participant_id', $participant->id)
            ->orderByDesc('scheduled_start')->limit(20)
            ->get(['id', 'scheduled_start as date', 'appointment_type', 'status'])
            ->map(fn ($a) => ['kind' => 'appointment', 'date' => $a->date, 'data' => $a]);

        $merged = collect()
            ->concat($notes)->concat($orders)->concat($vitals)->concat($appts)
            ->sortByDesc(fn ($e) => is_string($e['date']) ? $e['date'] : $e['date']?->toIso8601String())
            ->values()->take(100);

        return response()->json(['timeline' => $merged]);
    }

    /** GET /note-reminders/upcoming : quarterly reassessment queue. */
    public function noteRemindersUpcoming(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        // A participant is "due for quarterly" if their latest clinical note is
        // older than 85 days (warning) or 90+ days (overdue).
        $rows = DB::table('emr_participants as p')
            ->where('p.tenant_id', $u->tenant_id)
            ->where('p.enrollment_status', 'enrolled')
            ->leftJoinSub(
                DB::table('emr_clinical_notes')
                    ->select('participant_id', DB::raw('MAX(visit_date) as last_visit'))
                    ->where('tenant_id', $u->tenant_id)
                    ->groupBy('participant_id'),
                'n', 'n.participant_id', '=', 'p.id',
            )
            ->where(function ($q) {
                $q->whereNull('n.last_visit')->orWhere('n.last_visit', '<', now()->subDays(85)->toDateString());
            })
            ->select('p.id', 'p.mrn', 'p.first_name', 'p.last_name', 'n.last_visit')
            ->limit(200)->get();

        return response()->json(['rows' => $rows, 'count' => $rows->count()]);
    }
}
