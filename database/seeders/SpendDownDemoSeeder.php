<?php

namespace Database\Seeders;

use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\SpendDownPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 (MVP roadmap): Medicaid spend-down / share-of-cost demo data.
 *
 * Picks a handful of dual-eligible participants per tenant, sets up a
 * monthly share-of-cost obligation + a mix of paid / partially-paid /
 * overdue months so the Finance dashboard widget and the Insurance-tab
 * sub-panel have meaningful content on first view.
 */
class SpendDownDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = DB::table('shared_tenants')->pluck('id');

        foreach ($tenants as $tenantId) {
            // Determine state heuristic: West → CA, East → NY, Central → FL.
            $tenantName = (string) DB::table('shared_tenants')->where('id', $tenantId)->value('name');
            $state = match (true) {
                str_contains(strtolower($tenantName), 'east')    => 'NY',
                str_contains(strtolower($tenantName), 'central') => 'FL',
                default                                          => 'CA',
            };

            $participants = Participant::where('tenant_id', $tenantId)
                ->where('enrollment_status', 'enrolled')
                ->inRandomOrder()
                ->take(6)
                ->get();

            if ($participants->isEmpty()) {
                continue;
            }

            $recorder = User::where('tenant_id', $tenantId)
                ->where('department', 'finance')
                ->first()
                ?? User::where('tenant_id', $tenantId)->first();

            if (! $recorder) {
                continue;
            }

            foreach ($participants as $idx => $participant) {
                $coverage = InsuranceCoverage::where('participant_id', $participant->id)
                    ->where('payer_type', 'medicaid')
                    ->where('is_active', true)
                    ->first();

                // Create one if the participant has no medicaid coverage yet.
                if (! $coverage) {
                    $coverage = InsuranceCoverage::create([
                        'participant_id' => $participant->id,
                        'payer_type'     => 'medicaid',
                        'plan_name'      => match ($state) {
                            'NY'    => 'NY Medicaid Surplus',
                            'FL'    => 'FL Medicaid Spend-Down',
                            default => 'Medi-Cal Share-of-Cost',
                        },
                        'member_id'      => $participant->medicaid_id ?? ('MCD' . str_pad((string) $participant->id, 9, '0', STR_PAD_LEFT)),
                        'effective_date' => $participant->enrollment_date ?? now()->subYear()->toDateString(),
                        'is_active'      => true,
                    ]);
                }

                // Rotate a small variety of monthly share-of-cost amounts.
                $socAmount = [450.00, 275.50, 612.00, 125.00, 880.00, 350.00][$idx % 6];

                $coverage->update([
                    'share_of_cost_monthly_amount' => $socAmount,
                    'spend_down_threshold'         => null,
                    'spend_down_period_start'      => now()->startOfYear()->toDateString(),
                    'spend_down_period_end'        => now()->endOfYear()->toDateString(),
                    'spend_down_state'             => $state,
                ]);

                // Payment history across the last 4 months with a mix of
                // fully-paid, partially-paid, and unpaid periods.
                for ($m = 3; $m >= 0; $m--) {
                    $period = Carbon::now()->subMonths($m);
                    $periodYm = $period->format('Y-m');

                    $scenario = ($idx + $m) % 4;
                    // scenario 0 = fully paid, 1 = partial, 2 = unpaid (skip), 3 = split (two partials)

                    if ($scenario === 2) {
                        continue;
                    }

                    if ($scenario === 0) {
                        SpendDownPayment::create([
                            'tenant_id'         => $tenantId,
                            'participant_id'    => $participant->id,
                            'amount'            => $socAmount,
                            'paid_at'           => $period->copy()->day(min(10, $period->daysInMonth))->toDateString(),
                            'period_month_year' => $periodYm,
                            'payment_method'    => 'check',
                            'reference_number'  => 'CHK-' . $period->format('Ym') . '-' . str_pad((string) $participant->id, 4, '0', STR_PAD_LEFT),
                            'notes'             => 'Auto-seeded demo payment',
                            'recorded_by_user_id' => $recorder?->id,
                        ]);
                    } elseif ($scenario === 1) {
                        SpendDownPayment::create([
                            'tenant_id'         => $tenantId,
                            'participant_id'    => $participant->id,
                            'amount'            => round($socAmount * 0.6, 2),
                            'paid_at'           => $period->copy()->day(min(12, $period->daysInMonth))->toDateString(),
                            'period_month_year' => $periodYm,
                            'payment_method'    => 'eft',
                            'reference_number'  => 'EFT-' . $period->format('Ym') . '-' . str_pad((string) $participant->id, 4, '0', STR_PAD_LEFT),
                            'notes'             => 'Partial — demo',
                            'recorded_by_user_id' => $recorder?->id,
                        ]);
                    } else { // 3 → split pair
                        SpendDownPayment::create([
                            'tenant_id'         => $tenantId,
                            'participant_id'    => $participant->id,
                            'amount'            => round($socAmount * 0.5, 2),
                            'paid_at'           => $period->copy()->day(min(5, $period->daysInMonth))->toDateString(),
                            'period_month_year' => $periodYm,
                            'payment_method'    => 'cash',
                            'reference_number'  => null,
                            'notes'             => 'Split payment 1/2',
                            'recorded_by_user_id' => $recorder?->id,
                        ]);
                        SpendDownPayment::create([
                            'tenant_id'         => $tenantId,
                            'participant_id'    => $participant->id,
                            'amount'            => round($socAmount * 0.5, 2),
                            'paid_at'           => $period->copy()->day(min(20, $period->daysInMonth))->toDateString(),
                            'period_month_year' => $periodYm,
                            'payment_method'    => 'money_order',
                            'reference_number'  => 'MO-' . $period->format('Ym') . '-' . str_pad((string) $participant->id, 4, '0', STR_PAD_LEFT),
                            'notes'             => 'Split payment 2/2',
                            'recorded_by_user_id' => $recorder?->id,
                        ]);
                    }
                }
            }
        }

        $this->command?->info('    Spend-down demo data seeded.');
    }
}
