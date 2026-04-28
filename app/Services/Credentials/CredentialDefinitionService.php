<?php

// ─── CredentialDefinitionService ─────────────────────────────────────────────
// Resolves which credential definitions apply to a given user, taking into
// account: org-level definitions (always required if targeting matches),
// per-site disabled overrides (suppress non-mandatory defs for that site),
// and site-only extra definitions (apply when site_id matches).
//
// Targeting follows OR semantics : a user matches a definition if ANY target
// row matches (their dept) OR (their job_title) OR (one of their designations).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Credentials;

use App\Models\CredentialDefinition;
use App\Models\StaffCredential;
use App\Models\User;
use Illuminate\Support\Collection;

class CredentialDefinitionService
{
    /**
     * Definitions that target the user (post site-override resolution).
     *
     * @return Collection<int, CredentialDefinition>
     */
    public function activeForUser(User $user): Collection
    {
        // Pull all definitions for the tenant (org-level + this user's site-only extras),
        // along with their targets and any site-disable overrides for this user's site.
        $definitions = CredentialDefinition::forTenant($user->tenant_id)
            ->active()
            ->with(['targets', 'siteOverrides'])
            ->where(function ($q) use ($user) {
                $q->whereNull('site_id')
                  ->orWhere('site_id', $user->site_id);
            })
            ->get();

        return $definitions->filter(function (CredentialDefinition $def) use ($user) {
            // 1) Suppress if this site has disabled the def (only valid for non-mandatory)
            if (! $def->is_cms_mandatory && $user->site_id !== null) {
                $disabled = $def->siteOverrides
                    ->where('site_id', $user->site_id)
                    ->where('action', 'disabled')
                    ->isNotEmpty();
                if ($disabled) return false;
            }

            // 2) Match targeting rules : OR semantics
            return $this->userMatchesDefinition($user, $def);
        })->values();
    }

    /** True iff any target rule matches the user's dept / job_title / designations. */
    public function userMatchesDefinition(User $user, CredentialDefinition $def): bool
    {
        $designations = is_array($user->designations) ? $user->designations : [];

        foreach ($def->targets as $target) {
            $match = match ($target->target_kind) {
                'department'  => $target->target_value === $user->department,
                'job_title'   => $target->target_value === $user->job_title,
                'designation' => in_array($target->target_value, $designations, true),
                default       => false,
            };
            if ($match) return true;
        }
        return false;
    }

    /**
     * Definitions a user is required to hold but for which no active credential
     * exists in emr_staff_credentials. Used by gap detection + dashboard.
     *
     * @return Collection<int, CredentialDefinition>
     */
    public function missingForUser(User $user): Collection
    {
        $required = $this->activeForUser($user);
        if ($required->isEmpty()) return collect();

        // A credential "covers" a definition if:
        //  - it's linked to that definition_id, AND
        //  - it's the tip of the chain (no replaced_by_credential_id : older
        //    superseded versions are audit-history only), AND
        //  - it's not expired AND status is active or pending
        $heldDefIds = StaffCredential::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNotNull('credential_definition_id')
            ->whereNull('replaced_by_credential_id')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNull('expires_at')
                       ->orWhere('expires_at', '>=', now()->toDateString());
                })
                  ->whereIn('cms_status', ['active', 'pending']);
            })
            ->pluck('credential_definition_id')
            ->all();

        return $required->reject(fn ($d) => in_array($d->id, $heldDefIds, true))->values();
    }
}
