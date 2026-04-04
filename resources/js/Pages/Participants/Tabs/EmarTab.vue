<script setup lang="ts">
// ─── EmarTab.vue ──────────────────────────────────────────────────────────────
// Electronic Medication Administration Record (eMAR). Displays today's MAR
// schedule grouped by scheduled time. Administer dose button opens a confirm
// modal. DEA schedule II/III requires witness_user_id. Shows administration
// history for the current day with administered_by and timestamp.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { CheckCircleIcon, ClockIcon } from '@heroicons/vue/24/outline'

interface EmarRecord {
    id: number
    medication_id: number
    scheduled_time: string | null
    administered_at: string | null
    status: string
    dose_given: string | null
    route_given: string | null
    notes: string | null
    witness_user_id: number | null
    administered_by: { id: number; first_name: string; last_name: string } | null
    medication: {
        id: number
        drug_name: string
        dose: string | null
        route: string | null
        dea_schedule: string | null
    }
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    emarRecords?: EmarRecord[]
}>()

const records = ref<EmarRecord[]>(props.emarRecords ?? [])
const administeringId = ref<number | null>(null)
const showAdminModal = ref(false)
const selectedRecord = ref<EmarRecord | null>(null)
const adminForm = ref({ dose_given: '', route_given: '', notes: '', witness_user_id: '' })
const adminError = ref('')

const STATUS_COLORS: Record<string, string> = {
    given: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    late: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300',
    missed: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-500',
    held: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
    refused: 'bg-orange-100 dark:bg-orange-900/60 text-orange-800 dark:text-orange-300',
    scheduled: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
}

const pendingRecords = computed(() =>
    records.value.filter((r) => r.status === 'scheduled' || r.status === 'late'),
)
const administeredRecords = computed(() => records.value.filter((r) => r.status === 'given'))
const otherRecords = computed(() =>
    records.value.filter((r) => !['scheduled', 'late', 'given'].includes(r.status)),
)

function fmtTime(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val)
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}

function openAdminModal(record: EmarRecord) {
    selectedRecord.value = record
    adminForm.value = {
        dose_given: record.medication.dose ?? '',
        route_given: record.medication.route ?? '',
        notes: '',
        witness_user_id: '',
    }
    adminError.value = ''
    showAdminModal.value = true
}

async function confirmAdminister() {
    if (!selectedRecord.value) return
    const rec = selectedRecord.value
    const needsWitness =
        rec.medication.dea_schedule === 'II' || rec.medication.dea_schedule === 'III'
    if (needsWitness && !adminForm.value.witness_user_id) {
        adminError.value = 'Witness user ID is required for Schedule II/III medications.'
        return
    }
    administeringId.value = rec.id
    try {
        const payload: Record<string, unknown> = {
            dose_given: adminForm.value.dose_given || null,
            route_given: adminForm.value.route_given || null,
            notes: adminForm.value.notes || null,
        }
        if (adminForm.value.witness_user_id)
            payload.witness_user_id = parseInt(adminForm.value.witness_user_id)
        const res = await axios.post(
            `/participants/${props.participant.id}/emar/${rec.id}/administer`,
            payload,
        )
        const idx = records.value.findIndex((r) => r.id === rec.id)
        if (idx !== -1) records.value[idx] = res.data
        showAdminModal.value = false
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        adminError.value = e.response?.data?.message ?? 'Failed to record administration.'
    } finally {
        administeringId.value = null
    }
}
</script>

<template>
    <div class="p-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">
            eMAR — Today's Schedule
        </h2>

        <div
            v-if="records.length === 0"
            class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm"
        >
            No MAR records for today.
        </div>

        <!-- Pending / Due -->
        <div v-if="pendingRecords.length > 0" class="mb-6">
            <h3
                class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1"
            >
                <ClockIcon class="w-3.5 h-3.5" /> Due / Pending
            </h3>
            <div class="space-y-1.5">
                <div
                    v-for="rec in pendingRecords"
                    :key="rec.id"
                    class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
                >
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{
                                rec.medication.drug_name
                            }}</span>
                            <span
                                v-if="rec.medication.dea_schedule"
                                class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded"
                                >Sched {{ rec.medication.dea_schedule }}</span
                            >
                            <span
                                :class="[
                                    'text-xs px-1.5 py-0.5 rounded',
                                    STATUS_COLORS[rec.status] ?? '',
                                ]"
                                >{{ rec.status }}</span
                            >
                        </div>
                        <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                            {{ rec.medication.dose }} · {{ rec.medication.route }} · Scheduled
                            {{ fmtTime(rec.scheduled_time) }}
                        </div>
                    </div>
                    <button
                        class="text-xs px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 transition-colors shrink-0"
                        @click="openAdminModal(rec)"
                    >
                        Administer
                    </button>
                </div>
            </div>
        </div>

        <!-- Administered -->
        <div v-if="administeredRecords.length > 0" class="mb-6">
            <h3
                class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1"
            >
                <CheckCircleIcon class="w-3.5 h-3.5" /> Administered
            </h3>
            <div class="space-y-1">
                <div
                    v-for="rec in administeredRecords"
                    :key="rec.id"
                    class="flex items-center gap-3 bg-green-50 dark:bg-green-950/20 border border-green-100 dark:border-green-900/40 rounded-lg px-4 py-2"
                >
                    <span class="text-sm text-gray-800 dark:text-slate-200 flex-1">{{
                        rec.medication.drug_name
                    }}</span>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{
                        fmtTime(rec.administered_at)
                    }}</span>
                    <span
                        v-if="rec.administered_by"
                        class="text-xs text-gray-400 dark:text-slate-500"
                        >{{ rec.administered_by.first_name[0] }}.
                        {{ rec.administered_by.last_name }}</span
                    >
                </div>
            </div>
        </div>

        <!-- Missed / Held / Refused -->
        <div v-if="otherRecords.length > 0">
            <h3
                class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2"
            >
                Other
            </h3>
            <div class="space-y-1">
                <div
                    v-for="rec in otherRecords"
                    :key="rec.id"
                    class="flex items-center gap-3 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-lg px-4 py-2"
                >
                    <span class="text-sm text-gray-600 dark:text-slate-400 flex-1">{{
                        rec.medication.drug_name
                    }}</span>
                    <span
                        :class="['text-xs px-1.5 py-0.5 rounded', STATUS_COLORS[rec.status] ?? '']"
                        >{{ rec.status }}</span
                    >
                    <span v-if="rec.notes" class="text-xs text-gray-400 dark:text-slate-500">{{
                        rec.notes
                    }}</span>
                </div>
            </div>
        </div>

        <!-- Administer modal -->
        <div
            v-if="showAdminModal && selectedRecord"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-sm w-full p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">
                    Administer: {{ selectedRecord.medication.drug_name }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Dose Given</label
                        >
                        <input
                            v-model="adminForm.dose_given"
                            type="text"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Route Given</label
                        >
                        <input
                            v-model="adminForm.route_given"
                            type="text"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div
                        v-if="
                            selectedRecord.medication.dea_schedule === 'II' ||
                            selectedRecord.medication.dea_schedule === 'III'
                        "
                    >
                        <label class="block text-xs font-medium text-red-600 dark:text-red-400 mb-1"
                            >Witness User ID (required for Sched
                            {{ selectedRecord.medication.dea_schedule }})</label
                        >
                        <input
                            v-model="adminForm.witness_user_id"
                            type="number"
                            class="w-full text-sm border border-red-300 dark:border-red-700 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Notes</label
                        >
                        <input
                            v-model="adminForm.notes"
                            type="text"
                            placeholder="Optional"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                </div>
                <p v-if="adminError" class="text-red-600 dark:text-red-400 text-xs mt-2">
                    {{ adminError }}
                </p>
                <div class="flex gap-2 mt-4">
                    <button
                        :disabled="administeringId !== null"
                        class="text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
                        @click="confirmAdminister"
                    >
                        {{ administeringId !== null ? 'Recording...' : 'Confirm Administration' }}
                    </button>
                    <button
                        class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                        @click="showAdminModal = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
