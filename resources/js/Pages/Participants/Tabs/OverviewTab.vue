<script setup lang="ts">
// ─── Tabs/OverviewTab.vue ─────────────────────────────────────────────────────
// Printable facesheet (overview) for the participant. Shows demographics,
// addresses, emergency contacts, active flags, and a print-media CSS injection
// that hides the navigation when the browser's Print function is used.
// ─────────────────────────────────────────────────────────────────────────────

import { onMounted, onUnmounted } from 'vue'
import { ExclamationTriangleIcon, PrinterIcon } from '@heroicons/vue/24/outline'

interface Participant {
    id: number
    first_name: string
    last_name: string
    dob: string | null
    mrn: string
    status: string
    enrollment_date: string | null
    primary_language: string | null
    gender: string | null
    advance_directive_status: string | null
    has_dnr: boolean
    photo_url: string | null
    site_name: string | null
}
interface Address {
    id: number
    address_type: string
    street_line_1: string
    street_line_2: string | null
    city: string
    state: string
    zip_code: string
    is_primary: boolean
}
interface Contact {
    id: number
    contact_type: string
    full_name: string
    relationship: string | null
    phone_primary: string | null
    email: string | null
    is_emergency_contact: boolean
    is_primary_caregiver: boolean
}
interface Flag {
    id: number
    flag_type: string
    severity: string
    notes: string | null
    is_active: boolean
}

const props = defineProps<{
    participant: Participant
    addresses: Address[]
    contacts: Contact[]
    flags: Flag[]
}>()

const FLAG_SEVERITY_COLORS: Record<string, string> = {
    low: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    medium: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300',
    high: 'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300',
    critical: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 font-bold',
}

const FLAG_LABELS: Record<string, string> = {
    wheelchair: 'Wheelchair',
    fall_risk: 'Fall Risk',
    dnr: 'DNR',
    hospice: 'Hospice',
    dementia: 'Dementia',
    behavior: 'Behavior',
    isolation: 'Isolation',
    elopement_risk: 'Elopement Risk',
    oxygen: 'Oxygen',
    dietary: 'Dietary',
    wound_care: 'Wound Care',
    pain_management: 'Pain Management',
    other: 'Other',
}

function fmtDate(val: string | null): string {
    if (!val) return '-'
    return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

function calcAge(dob: string | null): string {
    if (!dob) return '-'
    const d = new Date(dob.slice(0, 10) + 'T12:00:00')
    const today = new Date()
    let age = today.getFullYear() - d.getFullYear()
    if (
        today.getMonth() < d.getMonth() ||
        (today.getMonth() === d.getMonth() && today.getDate() < d.getDate())
    )
        age--
    return String(age)
}

function primaryAddress(addrs: Address[]): Address | null {
    return addrs.find((a) => a.is_primary) ?? addrs[0] ?? null
}

function emergencyContacts(ctcts: Contact[]): Contact[] {
    return ctcts.filter((c) => c.is_emergency_contact)
}

// Inject print CSS on mount, remove on unmount
let styleEl: HTMLStyleElement | null = null
onMounted(() => {
    styleEl = document.createElement('style')
    styleEl.textContent =
        '@media print { nav, header, aside, [data-no-print] { display: none !important; } }'
    document.head.appendChild(styleEl)
})
onUnmounted(() => {
    styleEl?.remove()
})

function printFacesheet() {
    window.print()
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Facesheet</h3>
            <button
                class="text-xs px-3 py-1.5 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-200 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors flex items-center gap-1.5"
                aria-label="Print facesheet"
                @click="printFacesheet"
            >
                <PrinterIcon class="w-4 h-4" />
                Print
            </button>
        </div>

        <!-- Demographics -->
        <section class="mb-6">
            <h4
                class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-3"
            >
                Demographics
            </h4>
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div>
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Full Name
                    </dt>
                    <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {{ participant.first_name }} {{ participant.last_name }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        MRN
                    </dt>
                    <dd class="mt-0.5 text-sm font-mono text-gray-900 dark:text-slate-100">
                        {{ participant.mrn }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Date of Birth
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ fmtDate(participant.dob) }} (age {{ calcAge(participant.dob) }})
                    </dd>
                </div>
                <div v-if="participant.gender">
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Gender
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ participant.gender }}
                    </dd>
                </div>
                <div v-if="participant.primary_language">
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Language
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ participant.primary_language }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Status
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ participant.status }}
                    </dd>
                </div>
                <div v-if="participant.enrollment_date">
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Enrolled
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ fmtDate(participant.enrollment_date) }}
                    </dd>
                </div>
                <div v-if="participant.advance_directive_status">
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        Advance Directive
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                        {{ participant.advance_directive_status }}
                    </dd>
                </div>
                <div v-if="participant.has_dnr">
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                    >
                        DNR
                    </dt>
                    <dd class="mt-0.5 text-sm font-bold text-red-600 dark:text-red-400">Yes</dd>
                </div>
            </dl>
        </section>

        <!-- Primary Address -->
        <section v-if="primaryAddress(addresses)" class="mb-6">
            <h4
                class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-3"
            >
                Primary Address
            </h4>
            <p class="text-sm text-gray-800 dark:text-slate-200">
                {{ primaryAddress(addresses)!.street_line_1 }}
                <span v-if="primaryAddress(addresses)!.street_line_2"
                    >, {{ primaryAddress(addresses)!.street_line_2 }}</span
                ><br />
                {{ primaryAddress(addresses)!.city }}, {{ primaryAddress(addresses)!.state }}
                {{ primaryAddress(addresses)!.zip_code }}
            </p>
        </section>

        <!-- Emergency Contacts -->
        <section v-if="emergencyContacts(contacts).length > 0" class="mb-6">
            <h4
                class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-3"
            >
                Emergency Contacts
            </h4>
            <div class="space-y-2">
                <div
                    v-for="c in emergencyContacts(contacts)"
                    :key="c.id"
                    class="text-sm text-gray-800 dark:text-slate-200"
                >
                    <span class="font-semibold">{{ c.full_name }}</span>
                    <span v-if="c.relationship" class="text-gray-500 dark:text-slate-400 ml-1"
                        >({{ c.relationship }})</span
                    >
                    <span v-if="c.phone_primary" class="ml-2 font-mono">{{ c.phone_primary }}</span>
                </div>
            </div>
        </section>

        <!-- Active Flags -->
        <section v-if="flags.filter((f) => f.is_active).length > 0" class="mb-2">
            <h4
                class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-3"
            >
                <ExclamationTriangleIcon class="w-4 h-4 inline mr-1 text-amber-500" />
                Active Flags
            </h4>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="f in flags.filter((f) => f.is_active)"
                    :key="f.id"
                    :class="`text-xs px-2.5 py-1 rounded-full ${FLAG_SEVERITY_COLORS[f.severity] ?? 'bg-gray-100 text-gray-600'}`"
                    >{{ FLAG_LABELS[f.flag_type] ?? f.flag_type }}</span
                >
            </div>
        </section>
    </div>
</template>
