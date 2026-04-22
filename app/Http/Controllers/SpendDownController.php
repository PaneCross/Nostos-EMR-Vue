<?php

// ─── SpendDownController ──────────────────────────────────────────────────────
// Medicaid spend-down / share-of-cost workflow endpoints. Phase 7 (MVP roadmap).
//
// Routes:
//   GET  /participants/{p}/spend-down                — JSON status + payment history
//   POST /participants/{p}/spend-down/coverage       — update coverage spend-down fields
//   POST /participants/{p}/spend-down/payments      — record a payment
//   DELETE /spend-down/payments/{payment}            — soft-delete a payment
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\SpendDownPayment;
use App\Services\SpendDownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SpendDownController extends Controller
{
    public function __construct(private SpendDownService $service) {}

    private function gate(Request $request, Participant $participant): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        $can = $u->isSuperAdmin()
            || in_array($u->department, ['finance', 'enrollment', 'it_admin', 'qa_compliance'], true);
        abort_unless($can, 403, 'Spend-down management requires finance / enrollment / QA / IT admin.');
    }

    public function show(Request $request, Participant $participant): JsonResponse
    {
        $this->gate($request, $participant);

        $coverage = $this->service->activeSpendDownCoverage($participant);
        $periodYm = Carbon::today()->format('Y-m');

        $status = $this->service->periodStatus($participant, $periodYm);

        // Payment history (last 12 periods)
        $since = Carbon::today()->subMonths(12)->format('Y-m');
        $payments = SpendDownPayment::forTenant($participant->tenant_id)
            ->where('participant_id', $participant->id)
            ->where('period_month_year', '>=', $since)
            ->with('recordedBy:id,first_name,last_name')
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get()
            ->map(fn (SpendDownPayment $p) => [
                'id'                => $p->id,
                'amount'            => (float) $p->amount,
                'paid_at'           => $p->paid_at?->toDateString(),
                'period'            => $p->period_month_year,
                'payment_method'    => $p->payment_method,
                'method_label'      => $p->methodLabel(),
                'reference_number'  => $p->reference_number,
                'notes'             => $p->notes,
                'recorded_by'       => $p->recordedBy
                    ? $p->recordedBy->first_name . ' ' . $p->recordedBy->last_name
                    : null,
            ]);

        return response()->json([
            'coverage' => $coverage ? [
                'id'                            => $coverage->id,
                'has_spend_down'                => $coverage->hasSpendDown(),
                'share_of_cost_monthly_amount'  => (float) $coverage->share_of_cost_monthly_amount,
                'spend_down_threshold'          => (float) $coverage->spend_down_threshold,
                'spend_down_period_start'       => $coverage->spend_down_period_start?->toDateString(),
                'spend_down_period_end'         => $coverage->spend_down_period_end?->toDateString(),
                'spend_down_state'              => $coverage->spend_down_state,
                'plan_name'                     => $coverage->plan_name,
            ] : null,
            'current_status' => $status,
            'payments'       => $payments,
            'methods'        => SpendDownPayment::METHOD_LABELS,
        ]);
    }

    public function updateCoverage(Request $request, Participant $participant): JsonResponse
    {
        $this->gate($request, $participant);

        $v = $request->validate([
            'coverage_id'                    => ['required', 'integer', 'exists:emr_insurance_coverages,id'],
            'share_of_cost_monthly_amount'   => ['nullable', 'numeric', 'min:0'],
            'spend_down_threshold'           => ['nullable', 'numeric', 'min:0'],
            'spend_down_period_start'        => ['nullable', 'date'],
            'spend_down_period_end'          => ['nullable', 'date', 'after_or_equal:spend_down_period_start'],
            'spend_down_state'               => ['nullable', 'string', 'size:2'],
        ]);

        $coverage = InsuranceCoverage::where('id', $v['coverage_id'])
            ->where('participant_id', $participant->id)
            ->firstOrFail();

        // Only medicaid coverages carry spend-down.
        abort_if($coverage->payer_type !== 'medicaid', 422, 'Spend-down only applies to Medicaid coverage.');

        $coverage->update([
            'share_of_cost_monthly_amount' => $v['share_of_cost_monthly_amount'] ?? null,
            'spend_down_threshold'         => $v['spend_down_threshold'] ?? null,
            'spend_down_period_start'      => $v['spend_down_period_start'] ?? null,
            'spend_down_period_end'        => $v['spend_down_period_end'] ?? null,
            'spend_down_state'             => $v['spend_down_state'] ?? null,
        ]);

        AuditLog::record(
            action:       'spend_down.coverage_updated',
            tenantId:     $participant->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'insurance_coverage',
            resourceId:   $coverage->id,
            description:  "Spend-down fields updated for coverage #{$coverage->id}",
            newValues:    array_intersect_key($v, array_flip([
                'share_of_cost_monthly_amount', 'spend_down_threshold',
                'spend_down_period_start', 'spend_down_period_end', 'spend_down_state',
            ])),
        );

        return response()->json($coverage->fresh());
    }

    public function storePayment(Request $request, Participant $participant): JsonResponse
    {
        $this->gate($request, $participant);

        $v = $request->validate([
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'paid_at'           => ['required', 'date', 'before_or_equal:today'],
            'period_month_year' => ['required', 'string', 'regex:/^[0-9]{4}-[0-9]{2}$/'],
            'payment_method'    => ['required', Rule::in(SpendDownPayment::METHODS)],
            'reference_number'  => ['nullable', 'string', 'max:100'],
            'notes'             => ['nullable', 'string', 'max:4000'],
        ]);

        $payment = SpendDownPayment::create(array_merge($v, [
            'tenant_id'           => $participant->tenant_id,
            'participant_id'      => $participant->id,
            'recorded_by_user_id' => $request->user()->id,
        ]));

        AuditLog::record(
            action:       'spend_down.payment_recorded',
            tenantId:     $participant->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'spend_down_payment',
            resourceId:   $payment->id,
            description:  sprintf('Spend-down payment $%s for %s (%s)', number_format((float) $v['amount'], 2), $v['period_month_year'], $v['payment_method']),
        );

        return response()->json($payment, 201);
    }

    public function destroyPayment(Request $request, SpendDownPayment $payment): JsonResponse
    {
        abort_if($payment->tenant_id !== $request->user()->tenant_id, 403);
        $u = $request->user();
        $can = $u->isSuperAdmin()
            || in_array($u->department, ['finance', 'enrollment', 'it_admin', 'qa_compliance'], true);
        abort_unless($can, 403);

        $payment->delete();

        AuditLog::record(
            action:       'spend_down.payment_deleted',
            tenantId:     $payment->tenant_id,
            userId:       $u->id,
            resourceType: 'spend_down_payment',
            resourceId:   $payment->id,
            description:  'Spend-down payment removed.',
        );

        return response()->json(['ok' => true]);
    }
}
