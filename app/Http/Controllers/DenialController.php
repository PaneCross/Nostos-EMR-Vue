<?php

// ─── DenialController ──────────────────────────────────────────────────────────
//
// Manages the denial management workflow for the Finance department.
// Surfaces DenialRecord entries created automatically by Process835RemittanceJob
// when a CLP02 claim status of '3' (denied) is encountered in an 835 ERA file.
//
// 120-day deadline = Medicare appeal window per 42 CFR §405.942.
// The denial lifecycle (42 CFR §405.942 — 120-day CMS Medicare appeal deadline):
//   open → appealing → won | lost | written_off
//
// Route list:
//   GET  /finance/denials                    → index()   — Inertia list page
//   GET  /finance/denials/{denial}           → show()    — JSON detail
//   PATCH /finance/denials/{denial}          → update()  — update notes/assignment
//   POST  /finance/denials/{denial}/appeal   → appeal()  — mark as appealing
//   POST  /finance/denials/{denial}/write-off → writeOff() — write off denial
//
// Authorization:
//   All endpoints: finance, it_admin, super_admin
//   write-off requires finance (revenue cycle decision — not it_admin)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DenialRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DenialController extends Controller
{
    // ── Department guards ─────────────────────────────────────────────────────

    /** Abort 403 for users outside finance, it_admin, or super_admin. */
    private function authorizeRead(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    /**
     * Write-off is restricted to finance staff only — this is a revenue cycle
     * decision that should not be delegated to IT admins.
     */
    private function authorizeWriteOff(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && $user->department !== 'finance',
            403
        );
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * Render the Inertia denial management list page.
     *
     * Supports filters:
     *   ?status=   — open, appealing, won, lost, written_off
     *   ?category= — authorization, coding_error, timely_filing, duplicate, etc.
     *   ?overdue=1 — only denials past their 120-day appeal deadline
     *
     * Returns paginated denials (25 per page) with aggregate KPIs.
     *
     * GET /finance/denials
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRead($request);
        $tenantId = $request->user()->tenant_id;

        $query = DenialRecord::where('tenant_id', $tenantId)
            ->with(['remittanceClaim:id,patient_control_number,payer_claim_number,remittance_batch_id'])
            ->orderBy('appeal_deadline')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('category')) {
            $query->where('denial_category', $request->query('category'));
        }

        if ($request->query('overdue') === '1') {
            $query->overdueForAppeal();
        }

        $denials = $query->paginate(25);

        $denialData = $denials->through(fn (DenialRecord $d) => $this->toListItem($d));

        return Inertia::render('Finance/Denials', [
            'denials'     => $denialData,
            'kpis'        => $this->buildKpis($tenantId),
            'filters'     => [
                'status'   => $request->query('status', ''),
                'category' => $request->query('category', ''),
                'overdue'  => $request->query('overdue', ''),
            ],
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * Return denial record JSON detail.
     *
     * Used by the Finance/Denials.tsx detail slide-over panel.
     *
     * GET /finance/denials/{denial}
     */
    public function show(Request $request, DenialRecord $denialRecord): JsonResponse
    {
        $this->authorizeRead($request);
        abort_if($denialRecord->tenant_id !== $request->user()->tenant_id, 403);

        $denialRecord->load([
            'remittanceClaim.adjustments',
            'remittanceClaim.batch:id,payer_name,payment_date,check_eft_number',
        ]);

        return response()->json($this->toDetailItem($denialRecord));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update mutable fields on a denial record.
     *
     * Allows finance staff to add notes, assign to a user, or update
     * appeal-submitted date and appeal notes.
     *
     * Terminal denials (won/lost/written_off) cannot be updated.
     *
     * PATCH /finance/denials/{denial}
     */
    public function update(Request $request, DenialRecord $denialRecord): JsonResponse
    {
        $this->authorizeRead($request);
        abort_if($denialRecord->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($denialRecord->isTerminal(), 409, 'Terminal denial records cannot be updated.');

        $validated = $request->validate([
            'appeal_notes'           => 'nullable|string|max:5000',
            'resolution_notes'       => 'nullable|string|max:5000',
            'assigned_to_user_id'    => 'nullable|exists:shared_users,id',
            'appeal_submitted_date'  => 'nullable|date',
        ]);

        $denialRecord->update($validated);

        AuditLog::record(
            action:       'denial_record.updated',
            resourceType: 'DenialRecord',
            resourceId:   $denialRecord->id,
            tenantId:     $denialRecord->tenant_id,
            userId:       $request->user()->id,
            newValues:    $validated,
        );

        return response()->json([
            'message' => 'Denial record updated.',
            'denial'  => $this->toDetailItem($denialRecord->fresh()),
        ]);
    }

    // ── Appeal ────────────────────────────────────────────────────────────────

    /**
     * Transition a denial from 'open' to 'appealing'.
     *
     * Sets appeal_submitted_date to today and stores optional appeal notes.
     * Appeal deadline window is 120 days per 42 CFR §405.942.
     *
     * POST /finance/denials/{denial}/appeal
     */
    public function appeal(Request $request, DenialRecord $denialRecord): JsonResponse
    {
        $this->authorizeRead($request);
        abort_if($denialRecord->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($denialRecord->status !== 'open', 409, 'Only open denials can be appealed.');

        $validated = $request->validate([
            'appeal_notes' => 'nullable|string|max:5000',
        ]);

        $denialRecord->update([
            'status'                 => 'appealing',
            'appeal_submitted_date'  => now()->toDateString(),
            'appeal_notes'           => $validated['appeal_notes'] ?? null,
        ]);

        AuditLog::record(
            action:       'denial_record.appealed',
            resourceType: 'DenialRecord',
            resourceId:   $denialRecord->id,
            tenantId:     $denialRecord->tenant_id,
            userId:       $request->user()->id,
            newValues:    ['status' => 'appealing', 'appeal_submitted_date' => now()->toDateString()],
        );

        return response()->json([
            'message' => 'Denial marked as appealing.',
            'denial'  => $this->toDetailItem($denialRecord->fresh()),
        ]);
    }

    // ── Write Off ─────────────────────────────────────────────────────────────

    /**
     * Write off a denial as unrecoverable.
     *
     * Typically used when the appeal deadline has passed or the denial amount
     * does not justify the cost of appeal. Finance-only action.
     *
     * POST /finance/denials/{denial}/write-off
     */
    public function writeOff(Request $request, DenialRecord $denialRecord): JsonResponse
    {
        $this->authorizeWriteOff($request);
        abort_if($denialRecord->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($denialRecord->isTerminal(), 409, 'Denial is already in a terminal state.');

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:5000',
        ]);

        $denialRecord->update([
            'status'                  => 'written_off',
            'resolution_date'         => now()->toDateString(),
            'resolution_notes'        => $validated['resolution_notes'],
            'written_off_by_user_id'  => $request->user()->id,
            'written_off_at'          => now(),
        ]);

        AuditLog::record(
            action:       'denial_record.written_off',
            resourceType: 'DenialRecord',
            resourceId:   $denialRecord->id,
            tenantId:     $denialRecord->tenant_id,
            userId:       $request->user()->id,
            newValues:    [
                'status'           => 'written_off',
                'resolution_notes' => $validated['resolution_notes'],
            ],
        );

        return response()->json([
            'message' => 'Denial written off.',
            'denial'  => $this->toDetailItem($denialRecord->fresh()),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Map a DenialRecord to the compact shape used in the list view.
     */
    private function toListItem(DenialRecord $d): array
    {
        $days = $d->daysUntilAppealDeadline();

        return [
            'id'                     => $d->id,
            'status'                 => $d->status,
            'denial_category'        => $d->denial_category,
            'category_label'         => DenialRecord::CATEGORY_LABELS[$d->denial_category] ?? 'Other',
            'primary_reason_code'    => $d->primary_reason_code,
            'denial_reason'          => $d->denial_reason,
            'denied_amount'          => (float) $d->denied_amount,
            'denial_date'            => $d->denial_date,
            'appeal_deadline'        => $d->appeal_deadline,
            'days_until_deadline'    => $days,
            'is_overdue'             => $days < 0,
            'deadline_urgent'        => $days >= 0 && $days <= 14,
            'patient_control_number' => $d->remittanceClaim?->patient_control_number,
            'payer_claim_number'     => $d->remittanceClaim?->payer_claim_number,
            'encounter_log_id'       => $d->encounter_log_id,
            'appeal_submitted_date'  => $d->appeal_submitted_date,
            'resolution_date'        => $d->resolution_date,
            'assigned_to_user_id'    => $d->assigned_to_user_id,
        ];
    }

    /**
     * Map a DenialRecord to the full shape used in the detail panel.
     * Requires claim + adjustments + batch to be eager-loaded.
     */
    private function toDetailItem(DenialRecord $d): array
    {
        $base = $this->toListItem($d);

        $base['appeal_notes']      = $d->appeal_notes;
        $base['resolution_notes']  = $d->resolution_notes;
        $base['written_off_at']    = $d->written_off_at?->toIso8601String();

        // Claim-level adjustment detail
        if ($d->remittanceClaim && $d->remittanceClaim->adjustments->isNotEmpty()) {
            $base['adjustments'] = $d->remittanceClaim->adjustments->map(fn ($adj) => [
                'group_code'    => $adj->adjustment_group_code,
                'reason_code'   => $adj->reason_code,
                'amount'        => (float) $adj->adjustment_amount,
                'quantity'      => $adj->adjustment_quantity !== null ? (float) $adj->adjustment_quantity : null,
            ])->toArray();
        } else {
            $base['adjustments'] = [];
        }

        // Batch-level context
        if ($d->remittanceClaim?->batch) {
            $base['payer_name']     = $d->remittanceClaim->batch->payer_name;
            $base['payment_date']   = $d->remittanceClaim->batch->payment_date;
            $base['check_eft']      = $d->remittanceClaim->batch->check_eft_number;
            $base['batch_id']       = $d->remittanceClaim->batch->id;
        }

        return $base;
    }

    /**
     * Build denial management KPIs for the page header.
     */
    private function buildKpis(int $tenantId): array
    {
        $open      = DenialRecord::where('tenant_id', $tenantId)->where('status', 'open')->count();
        $appealing = DenialRecord::where('tenant_id', $tenantId)->where('status', 'appealing')->count();
        $overdue   = DenialRecord::where('tenant_id', $tenantId)->overdueForAppeal()->count();
        $dueSoon   = DenialRecord::where('tenant_id', $tenantId)->appealDueSoon(30)->count();

        $atRisk = DenialRecord::where('tenant_id', $tenantId)
            ->whereIn('status', ['open', 'appealing'])
            ->sum('denied_amount');

        $wonThisMonth = DenialRecord::where('tenant_id', $tenantId)
            ->where('status', 'won')
            ->whereMonth('resolution_date', now()->month)
            ->whereYear('resolution_date', now()->year)
            ->sum('denied_amount');

        return [
            'open_count'       => $open,
            'appealing_count'  => $appealing,
            'overdue_count'    => $overdue,
            'due_soon_count'   => $dueSoon,
            'revenue_at_risk'  => (float) $atRisk,
            'won_this_month'   => (float) $wonThisMonth,
        ];
    }
}
