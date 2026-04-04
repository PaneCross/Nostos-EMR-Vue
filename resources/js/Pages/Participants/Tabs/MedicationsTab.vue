<script setup lang="ts">
// ─── MedicationsTab.vue ───────────────────────────────────────────────────────
// Active medications list with drug interaction alerts banner. Add medication
// form with DEA schedule, route, frequency. Discontinue with reason. Shows
// prescriber and ordered date. Acknowledge drug interaction alert inline.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Medication {
    id: number
    drug_name: string
    dose: string | null
    route: string | null
    frequency: string | null
    dea_schedule: string | null
    indication: string | null
    status: string
    ordered_at: string | null
    discontinued_at: string | null
    discontinue_reason: string | null
    prescriber: { id: number; first_name: string; last_name: string } | null
}

interface DrugInteractionAlert {
    id: number
    drug_name_a: string
    drug_name_b: string
    severity: string
    description: string | null
    acknowledged_at: string | null
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    medications: Medication[]
    drugInteractionAlerts?: DrugInteractionAlert[]
}>()

const medications = ref<Medication[]>(props.medications)
const alerts = ref<DrugInteractionAlert[]>(props.drugInteractionAlerts ?? [])
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')
const discontinuingId = ref<number | null>(null)

const form = ref({
    drug_name: '',
    dose: '',
    route: 'oral',
    frequency: '',
    dea_schedule: '',
    indication: '',
    start_date: new Date().toISOString().slice(0, 10),
})

const activeMeds = computed(() => medications.value.filter((m) => m.status === 'active'))
const discontinuedMeds = computed(() => medications.value.filter((m) => m.status !== 'active'))
const unacknowledgedAlerts = computed(() => alerts.value.filter((a) => !a.acknowledged_at))

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
    if (!form.value.drug_name.trim()) {
        error.value = 'Drug name is required.'
        return
    }
    saving.value = true
    error.value = ''
    try {
        const res = await axios.post(
            `/participants/${props.participant.id}/medications`,
            form.value,
        )
        medications.value.unshift(res.data)
        showAddForm.value = false
        form.value = {
            drug_name: '',
            dose: '',
            route: 'oral',
            frequency: '',
            dea_schedule: '',
            indication: '',
            start_date: new Date().toISOString().slice(0, 10),
        }
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        error.value = e.response?.data?.message ?? 'Failed to save medication.'
        saving.value = false
    }
}

async function discontinue(med: Medication) {
    const reason = prompt('Reason for discontinuation (required):')
    if (!reason?.trim()) return
    discontinuingId.value = med.id
    try {
        const res = await axios.post(
            `/participants/${props.participant.id}/medications/${med.id}/discontinue`,
            { reason },
        )
        const idx = medications.value.findIndex((m) => m.id === med.id)
        if (idx !== -1) medications.value[idx] = res.data
    } catch {
        alert('Failed to discontinue medication.')
    } finally {
        discontinuingId.value = null
    }
}

async function acknowledgeAlert(alert: DrugInteractionAlert) {
    try {
        await axios.post(
            `/participants/${props.participant.id}/drug-interaction-alerts/${alert.id}/acknowledge`,
        )
        const idx = alerts.value.findIndex((a) => a.id === alert.id)
        if (idx !== -1) alerts.value[idx].acknowledged_at = new Date().toISOString()
    } catch {
        alert('Failed to acknowledge alert.')
    }
}
</script>

<template>
    <div class="p-6">
        <!-- Drug interaction alerts banner -->
        <div
            v-if="unacknowledgedAlerts.length > 0"
            class="mb-4 bg-orange-50 dark:bg-orange-950/30 border border-orange-300 dark:border-orange-700 rounded-lg p-3"
        >
            <div class="flex items-center gap-2 mb-2">
                <ExclamationTriangleIcon
                    class="w-4 h-4 text-orange-600 dark:text-orange-400 shrink-0"
                />
                <span class="text-sm font-semibold text-orange-800 dark:text-orange-300"
                    >Drug Interaction Alerts</span
                >
            </div>
            <div
                v-for="alert in unacknowledgedAlerts"
                :key="alert.id"
                class="flex items-start justify-between gap-3 text-xs text-orange-700 dark:text-orange-300 mb-1"
            >
                <span
                    ><span class="font-semibold">{{ alert.drug_name_a }}</span> +
                    <span class="font-semibold">{{ alert.drug_name_b }}</span
                    ><span v-if="alert.description"> — {{ alert.description }}</span></span
                >
                <button
                    class="shrink-0 text-xs underline hover:no-underline"
                    @click="acknowledgeAlert(alert)"
                >
                    Acknowledge
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Medications</h2>
            <button
                class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                @click="showAddForm = !showAddForm"
            >
                <PlusIcon class="w-3 h-3" />
                Add Medication
            </button>
        </div>

        <!-- Add medication form -->
        <div
            v-if="showAddForm"
            class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4"
        >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">
                Add Medication
            </h3>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Drug Name *</label
                    >
                    <input
                        v-model="form.drug_name"
                        type="text"
                        placeholder="e.g. Metformin 500mg"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Dose</label
                    >
                    <input
                        v-model="form.dose"
                        type="text"
                        placeholder="e.g. 500mg"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Route</label
                    >
                    <select
                        v-model="form.route"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    >
                        <option value="oral">Oral</option>
                        <option value="iv">IV</option>
                        <option value="im">IM</option>
                        <option value="subq">SubQ</option>
                        <option value="topical">Topical</option>
                        <option value="inhaled">Inhaled</option>
                        <option value="sublingual">Sublingual</option>
                        <option value="ophthalmic">Ophthalmic</option>
                        <option value="otic">Otic</option>
                        <option value="rectal">Rectal</option>
                        <option value="transdermal">Transdermal</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Frequency</label
                    >
                    <input
                        v-model="form.frequency"
                        type="text"
                        placeholder="e.g. BID with meals"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >DEA Schedule</label
                    >
                    <select
                        v-model="form.dea_schedule"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    >
                        <option value="">Non-controlled</option>
                        <option value="II">Schedule II</option>
                        <option value="III">Schedule III</option>
                        <option value="IV">Schedule IV</option>
                        <option value="V">Schedule V</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Indication</label
                    >
                    <input
                        v-model="form.indication"
                        type="text"
                        placeholder="Optional"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
            </div>
            <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
            <div class="flex gap-2">
                <button
                    :disabled="saving"
                    class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                    @click="submit"
                >
                    {{ saving ? 'Saving...' : 'Save' }}
                </button>
                <button
                    class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                    @click="showAddForm = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- Active medications -->
        <div
            v-if="activeMeds.length === 0 && !showAddForm"
            class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm"
        >
            No active medications.
        </div>
        <div v-else class="space-y-1.5 mb-4">
            <div
                v-for="med in activeMeds"
                :key="med.id"
                class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
            >
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{
                            med.drug_name
                        }}</span>
                        <span
                            v-if="med.dea_schedule"
                            class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded"
                            >Sched {{ med.dea_schedule }}</span
                        >
                    </div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                        <span v-if="med.dose">{{ med.dose }}</span>
                        <span v-if="med.route"> via {{ med.route }}</span>
                        <span v-if="med.frequency"> · {{ med.frequency }}</span>
                    </div>
                    <div
                        v-if="med.indication"
                        class="text-xs text-gray-400 dark:text-slate-500 mt-0.5"
                    >
                        For: {{ med.indication }}
                    </div>
                    <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                        Ordered {{ fmtDate(med.ordered_at) }}
                        <span v-if="med.prescriber">
                            by {{ med.prescriber.first_name[0] }}.
                            {{ med.prescriber.last_name }}</span
                        >
                    </div>
                </div>
                <button
                    :disabled="discontinuingId === med.id"
                    class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-red-50 dark:hover:bg-red-950/30 hover:border-red-300 dark:hover:border-red-700 hover:text-red-600 dark:hover:text-red-400 transition-colors disabled:opacity-50 shrink-0"
                    @click="discontinue(med)"
                >
                    Discontinue
                </button>
            </div>
        </div>

        <!-- Discontinued medications -->
        <div v-if="discontinuedMeds.length > 0">
            <h3
                class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2"
            >
                Discontinued
            </h3>
            <div class="space-y-1">
                <div
                    v-for="med in discontinuedMeds"
                    :key="med.id"
                    class="flex items-start gap-3 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-lg px-4 py-2 opacity-60"
                >
                    <div class="flex-1">
                        <span class="text-sm text-gray-600 dark:text-slate-400 line-through">{{
                            med.drug_name
                        }}</span>
                        <div
                            v-if="med.discontinue_reason"
                            class="text-xs text-gray-400 dark:text-slate-500 mt-0.5"
                        >
                            {{ med.discontinue_reason }}
                        </div>
                    </div>
                    <span class="text-xs text-gray-400 dark:text-slate-500 shrink-0">{{
                        fmtDate(med.discontinued_at)
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
