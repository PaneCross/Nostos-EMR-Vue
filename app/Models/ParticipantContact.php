<?php

// ─── ParticipantContact ───────────────────────────────────────────────────────
// People associated with a participant: emergency contacts, legal
// representatives (POA / guardian / healthcare proxy), family, neighbors.
//
// Used by clinicians at point of care, by Transportation when reaching the
// home, and by Social Work for care coordination. `priority_order` controls
// the call sequence; `is_legal_representative` gates who can sign consents
// when the participant lacks capacity. Soft-deletes preserve history.
//
// Notable rules:
//  - PHI under HIPAA — tenant-scoped through the parent Participant.
//  - 42 CFR §460.156 (rights) — legal representative role drives consent flow.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParticipantContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_participant_contacts';

    public const LEGAL_ROLES = ['durable_poa', 'healthcare_proxy', 'legal_guardian', 'court_appointed', 'none'];
    public const RELATIONSHIP_ROLES = ['spouse', 'partner', 'parent', 'child', 'sibling', 'grandchild', 'friend', 'other'];

    public const LEGAL_ROLE_LABELS = [
        'durable_poa'      => 'Durable Power of Attorney',
        'healthcare_proxy' => 'Healthcare Proxy',
        'legal_guardian'   => 'Legal Guardian',
        'court_appointed'  => 'Court-Appointed Representative',
        'none'             => 'None',
    ];

    protected $fillable = [
        'participant_id', 'contact_type',
        'first_name', 'last_name', 'relationship',
        'phone_primary', 'phone_secondary', 'email',
        'is_legal_representative', 'is_emergency_contact',
        'priority_order', 'notes',
        // Phase S1 — structured legal + relationship roles
        'legal_role', 'relationship_role',
    ];

    protected $casts = [
        'is_legal_representative' => 'boolean',
        'is_emergency_contact'    => 'boolean',
        'priority_order'          => 'integer',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
