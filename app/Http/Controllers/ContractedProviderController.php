<?php

// ─── ContractedProviderController — Phase S2 ───────────────────────────────
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContractedProvider;
use App\Models\ContractedProviderContract;
use App\Models\ContractedProviderRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContractedProviderController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['finance', 'qa_compliance', 'it_admin', 'executive', 'idt'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $providers = ContractedProvider::forTenant($u->tenant_id)
            ->with('contracts')
            ->orderBy('name')
            ->get()
            ->map(function (ContractedProvider $p) {
                $active = $p->activeContract();
                return array_merge($p->toArray(), [
                    'active_contract' => $active ? $active->only([
                        'id', 'contract_number', 'effective_date', 'termination_date',
                        'reimbursement_basis', 'reimbursement_value', 'requires_prior_auth_default',
                    ]) : null,
                ]);
            });

        if ($request->wantsJson()) {
            return response()->json(['providers' => $providers]);
        }
        return \Inertia\Inertia::render('Network/ContractedProviders', [
            'providers' => $providers,
            'provider_types' => ContractedProvider::PROVIDER_TYPES,
            'reimbursement_bases' => ContractedProviderContract::REIMBURSEMENT_BASES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'name'           => 'required|string|max:200',
            'npi'            => 'nullable|string|size:10',
            'tax_id'         => 'nullable|string|max:20',
            'provider_type'  => 'required|in:' . implode(',', ContractedProvider::PROVIDER_TYPES),
            'specialty'      => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'fax'            => 'nullable|string|max:30',
            'address_line1'  => 'nullable|string|max:200',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|size:2',
            'zip'            => 'nullable|string|max:10',
            'accepting_new_referrals' => 'boolean',
            'is_active'      => 'boolean',
            'notes'          => 'nullable|string|max:4000',
        ]);

        $provider = ContractedProvider::create(array_merge($validated, [
            'tenant_id' => $u->tenant_id,
        ]));

        AuditLog::record(
            action: 'contracted_provider.created',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'contracted_provider', resourceId: $provider->id,
            description: "Contracted provider added: {$provider->name} ({$provider->provider_type})",
        );

        return response()->json(['provider' => $provider], 201);
    }

    public function storeContract(Request $request, ContractedProvider $contractedProvider): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($contractedProvider->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'contract_number'      => 'nullable|string|max:60',
            'effective_date'       => 'required|date',
            'termination_date'     => 'nullable|date|after_or_equal:effective_date',
            'reimbursement_basis'  => 'required|in:' . implode(',', ContractedProviderContract::REIMBURSEMENT_BASES),
            'reimbursement_value'  => 'nullable|numeric|min:0',
            'requires_prior_auth_default' => 'boolean',
            'notes'                => 'nullable|string|max:4000',
        ]);

        $contract = ContractedProviderContract::create(array_merge($validated, [
            'tenant_id'              => $u->tenant_id,
            'contracted_provider_id' => $contractedProvider->id,
        ]));

        AuditLog::record(
            action: 'contracted_provider.contract_added',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'contracted_provider_contract', resourceId: $contract->id,
            description: "Contract added for {$contractedProvider->name}: {$contract->reimbursement_basis}",
        );

        return response()->json(['contract' => $contract], 201);
    }

    public function storeRate(Request $request, ContractedProviderContract $contract): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($contract->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'cpt_code'    => 'required|string|max:10',
            'modifier'    => 'nullable|string|max:4',
            'rate_amount' => 'required|numeric|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);

        $rate = ContractedProviderRate::updateOrCreate(
            [
                'contract_id' => $contract->id,
                'cpt_code'    => $validated['cpt_code'],
                'modifier'    => $validated['modifier'] ?? null,
            ],
            ['rate_amount' => $validated['rate_amount'], 'notes' => $validated['notes'] ?? null],
        );

        return response()->json(['rate' => $rate], 201);
    }

    public function showRates(Request $request, ContractedProviderContract $contract): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($contract->tenant_id !== $u->tenant_id, 403);
        return response()->json(['rates' => $contract->rates()->orderBy('cpt_code')->get()]);
    }
}
