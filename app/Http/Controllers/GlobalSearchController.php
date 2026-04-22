<?php

// ─── GlobalSearchController ──────────────────────────────────────────────────
// Phase 14.7 (MVP roadmap). Extends the existing participant-only cmd+K
// search to 6 entity types. Returns a single grouped JSON response so the
// UI can render one flat list with type-labeled sections.
//
// Supported entities:
//   participants | referrals | appointments | grievances | orders | sdrs
//
// All queries are tenant-scoped. Each kind capped at 6 results; overall cap
// ~36 rows — small enough to render a nice command palette without paging.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicalOrder;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GlobalSearchController extends Controller
{
    public const KINDS = ['participants', 'referrals', 'appointments', 'grievances', 'orders', 'sdrs'];

    public function index(Request $request): JsonResponse
    {
        $u = Auth::user();
        abort_if(!$u, 401);

        $validated = $request->validate([
            'q'     => ['required', 'string', 'min:2', 'max:100'],
            'kinds' => 'nullable|string',
        ]);
        $term = $validated['q'];
        $tenantId = $u->tenant_id;

        $kindsParam = $validated['kinds'] ?? null;
        $requestedKinds = $kindsParam
            ? array_values(array_intersect(self::KINDS, array_map('trim', explode(',', $kindsParam))))
            : self::KINDS;

        $groups = [];
        foreach ($requestedKinds as $kind) {
            $groups[$kind] = $this->searchKind($kind, $tenantId, $term);
        }

        AuditLog::record(
            action: 'global.search',
            tenantId: $tenantId,
            userId: $u->id,
            description: 'Global search q=' . substr($term, 0, 80),
        );

        return response()->json([
            'term'   => $term,
            'groups' => $groups,
            'total'  => array_sum(array_map('count', $groups)),
        ]);
    }

    private function searchKind(string $kind, int $tenantId, string $term): array
    {
        $like = '%' . $term . '%';
        return match ($kind) {
            'participants' => $this->searchParticipants($tenantId, $term, $like),
            'referrals'    => $this->searchReferrals($tenantId, $like),
            'appointments' => $this->searchAppointments($tenantId, $like),
            'grievances'   => $this->searchGrievances($tenantId, $like),
            'orders'       => $this->searchOrders($tenantId, $like),
            'sdrs'         => $this->searchSdrs($tenantId, $like),
            default        => [],
        };
    }

    private function searchParticipants(int $tenantId, string $term, string $like): array
    {
        $q = Participant::forTenant($tenantId)->with('site:id,name')->limit(6);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term)) {
            $q->searchByDob($term);
        } else {
            $q->search($term);
        }
        return $q->get()->map(fn ($p) => [
            'id'       => $p->id,
            'label'    => $p->fullName(),
            'sublabel' => 'MRN ' . $p->mrn . ' · ' . $p->site?->name,
            'href'     => '/participants/' . $p->id,
            'kind'     => 'participant',
        ])->all();
    }

    private function searchReferrals(int $tenantId, string $like): array
    {
        return Referral::forTenant($tenantId)
            ->where(function ($w) use ($like) {
                $w->where('prospective_first_name', 'ilike', $like)
                  ->orWhere('prospective_last_name', 'ilike', $like)
                  ->orWhere('referred_by_name', 'ilike', $like);
            })
            ->orderByDesc('created_at')->limit(6)->get()
            ->map(fn ($r) => [
                'id'       => $r->id,
                'label'    => trim(($r->prospective_first_name ?? '') . ' ' . ($r->prospective_last_name ?? '')) ?: ('Referral #' . $r->id),
                'sublabel' => 'Referral · status: ' . ($r->status ?? 'unknown'),
                'href'     => '/enrollment/referrals/' . $r->id,
                'kind'     => 'referral',
            ])->all();
    }

    private function searchAppointments(int $tenantId, string $like): array
    {
        return Appointment::where('tenant_id', $tenantId)
            ->with(['participant:id,first_name,last_name,mrn'])
            ->where(function ($w) use ($like) {
                $w->where('appointment_type', 'ilike', $like)
                  ->orWhere('notes', 'ilike', $like)
                  ->orWhereHas('participant', fn ($p) =>
                      $p->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('mrn', 'ilike', $like)
                  );
            })
            ->orderByDesc('scheduled_start')->limit(6)->get()
            ->map(fn ($a) => [
                'id'       => $a->id,
                'label'    => ucfirst(str_replace('_', ' ', (string) $a->appointment_type))
                              . ' · ' . ($a->participant ? $a->participant->first_name . ' ' . $a->participant->last_name : ''),
                'sublabel' => $a->scheduled_start?->format('Y-m-d H:i') . ' · ' . $a->status,
                'href'     => '/appointments/' . $a->id,
                'kind'     => 'appointment',
            ])->all();
    }

    private function searchGrievances(int $tenantId, string $like): array
    {
        return Grievance::forTenant($tenantId)
            ->with('participant:id,first_name,last_name,mrn')
            ->where(function ($w) use ($like) {
                $w->where('description', 'ilike', $like)
                  ->orWhere('category', 'ilike', $like)
                  ->orWhere('filed_by_name', 'ilike', $like);
            })
            ->orderByDesc('filed_at')->limit(6)->get()
            ->map(fn ($g) => [
                'id'       => $g->id,
                'label'    => $g->referenceNumber() . ' · ' . ucfirst(str_replace('_', ' ', (string) $g->category)),
                'sublabel' => ($g->participant ? $g->participant->first_name . ' ' . $g->participant->last_name . ' · ' : '')
                              . 'status: ' . $g->status,
                'href'     => '/grievances/' . $g->id,
                'kind'     => 'grievance',
            ])->all();
    }

    private function searchOrders(int $tenantId, string $like): array
    {
        if (! class_exists(ClinicalOrder::class)) return [];
        return ClinicalOrder::where('tenant_id', $tenantId)
            ->with('participant:id,first_name,last_name,mrn')
            ->where(function ($w) use ($like) {
                $w->where('order_type', 'ilike', $like)
                  ->orWhere('instructions', 'ilike', $like)
                  ->orWhere('clinical_indication', 'ilike', $like);
            })
            ->orderByDesc('created_at')->limit(6)->get()
            ->map(fn ($o) => [
                'id'       => $o->id,
                'label'    => 'Order · ' . ucfirst(str_replace('_', ' ', (string) $o->order_type)),
                'sublabel' => ($o->participant ? $o->participant->first_name . ' ' . $o->participant->last_name . ' · ' : '')
                              . 'status: ' . ($o->status ?? 'pending'),
                'href'     => '/clinical-orders/' . $o->id,
                'kind'     => 'order',
            ])->all();
    }

    private function searchSdrs(int $tenantId, string $like): array
    {
        if (! class_exists(Sdr::class)) return [];
        return Sdr::where('tenant_id', $tenantId)
            ->with('participant:id,first_name,last_name,mrn')
            ->where(function ($w) use ($like) {
                $w->where('description', 'ilike', $like)
                  ->orWhere('request_type', 'ilike', $like);
            })
            ->orderByDesc('created_at')->limit(6)->get()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'label'    => 'SDR #' . $s->id . ' · ' . (string) ($s->request_type ?? ''),
                'sublabel' => ($s->participant ? $s->participant->first_name . ' ' . $s->participant->last_name . ' · ' : '')
                              . 'status: ' . ($s->status ?? 'submitted'),
                'href'     => '/sdrs/' . $s->id,
                'kind'     => 'sdr',
            ])->all();
    }
}
