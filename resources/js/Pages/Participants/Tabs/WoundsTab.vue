<script setup lang="ts">
// ─── WoundsTab.vue ────────────────────────────────────────────────────────────
// Wound care management. Open wounds listed with stage severity color coding.
// Add assessment button per wound. Close wound action. New wound form captures
// location, type, and initial stage. Healed wounds shown collapsed at bottom.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface WoundAssessment {
    id: number
    assessed_at: string
    stage: string | null
    length_cm: number | null
    width_cm: number | null
    depth_cm: number | null
    exudate: string | null
    wound_bed: string | null
    periwound: string | null
    notes: string | null
    assessed_by: { id: number; first_name: string; last_name: string } | null
}

interface WoundRecord {
    id: number
    location: string
    wound_type: string
    current_stage: string | null
    status: string
    onset_date: string | null
    closed_date: string | null
    notes: string | null
    assessments: WoundAssessment[]
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    wounds: WoundRecord[]
}>()

const STAGE_COLORS: Record<string, string> = {
    stage_1: 'border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-950/20',
    stage_2: 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/20',
    stage_3: 'border-orange-400 dark:border-orange-600 bg-orange-50 dark:bg-orange-950/20',
    stage_4: 'border-red-400 dark:border-red-600 bg-red-50 dark:bg-red-950/20',
    unstageable: 'border-purple-400 dark:border-purple-600 bg-purple-50 dark:bg-purple-950/20',
    deep_tissue: 'border-red-600 dark:border-red-800 bg-red-100 dark:bg-red-950/40',
}

const wounds = ref<WoundRecord[]>(props.wounds)
const showAddWound = ref(false)
const savingWound = ref(false)
const woundError = ref('')
const addAssessmentForId = ref<number | null>(null)
const savingAssessment = ref(false)
const assessmentError = ref('')

const woundForm = ref({
    location: '',
    wound_type: 'pressure_injury',
    current_stage: 'stage_2',
    onset_date: new Date().toISOString().slice(0, 10),
    notes: '',
})

const assessmentForm = ref({
    assessed_at: new Date().toISOString().slice(0, 10),
    stage: '',
    length_cm: '',
    width_cm: '',
    depth_cm: '',
    exudate: '',
    wound_bed: '',
    notes: '',
})

const openWounds = computed(() => wounds.value.filter((w) => w.status === 'open'))
const healedWounds = computed(() => wounds.value.filter((w) => w.status !== 'open'))

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submitWound() {
    if (!woundForm.value.location.trim()) {
        woundError.value = 'Location is required.'
        return
    }
    savingWound.value = true
    woundError.value = ''
    try {
        const res = await axios.post(`/participants/${props.participant.id}/wounds`, {
            ...woundForm.value,
            notes: woundForm.value.notes || null,
        })
        wounds.value.unshift({ ...res.data, assessments: [] })
        showAddWound.value = false
        woundForm.value = {
            location: '',
            wound_type: 'pressure_injury',
            current_stage: 'stage_2',
            onset_date: new Date().toISOString().slice(0, 10),
            notes: '',
        }
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        woundError.value = e.response?.data?.message ?? 'Failed to save wound.'
        savingWound.value = false
    }
}

async function submitAssessment(woundId: number) {
    savingAssessment.value = true
    assessmentError.value = ''
    try {
        const payload = {
            assessed_at: assessmentForm.value.assessed_at,
            stage: assessmentForm.value.stage || null,
            length_cm:
                assessmentForm.value.length_cm !== ''
                    ? parseFloat(assessmentForm.value.length_cm)
                    : null,
            width_cm:
                assessmentForm.value.width_cm !== ''
                    ? parseFloat(assessmentForm.value.width_cm)
                    : null,
            depth_cm:
                assessmentForm.value.depth_cm !== ''
                    ? parseFloat(assessmentForm.value.depth_cm)
                    : null,
            exudate: assessmentForm.value.exudate || null,
            wound_bed: assessmentForm.value.wound_bed || null,
            notes: assessmentForm.value.notes || null,
        }
        const res = await axios.post(
            `/participants/${props.participant.id}/wounds/${woundId}/assessments`,
            payload,
        )
        const idx = wounds.value.findIndex((w) => w.id === woundId)
        if (idx !== -1) {
            wounds.value[idx].assessments.unshift(res.data)
            if (payload.stage) wounds.value[idx].current_stage = payload.stage
        }
        addAssessmentForId.value = null
        assessmentForm.value = {
            assessed_at: new Date().toISOString().slice(0, 10),
            stage: '',
            length_cm: '',
            width_cm: '',
            depth_cm: '',
            exudate: '',
            wound_bed: '',
            notes: '',
        }
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        assessmentError.value = e.response?.data?.message ?? 'Failed to save assessment.'
        savingAssessment.value = false
    }
}

async function closeWound(wound: WoundRecord) {
    if (!confirm(`Mark wound at "${wound.location}" as healed/closed?`)) return
    try {
        const res = await axios.post(
            `/participants/${props.participant.id}/wounds/${wound.id}/close`,
        )
        const idx = wounds.value.findIndex((w) => w.id === wound.id)
        if (idx !== -1) wounds.value[idx] = { ...wounds.value[idx], ...res.data }
    } catch {
        alert('Failed to close wound.')
    }
}
</script>

<template>
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Wound Care</h2>
            <button
                class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                @click="showAddWound = !showAddWound"
            >
                <PlusIcon class="w-3 h-3" />
                New Wound
            </button>
        </div>

        <!-- New wound form -->
        <div
            v-if="showAddWound"
            class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4"
        >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">New Wound</h3>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Location *</label
                    >
                    <input
                        v-model="woundForm.location"
                        type="text"
                        placeholder="e.g. Left sacrum"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Type</label
                    >
                    <select
                        v-model="woundForm.wound_type"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    >
                        <option value="pressure_injury">Pressure Injury</option>
                        <option value="venous_ulcer">Venous Ulcer</option>
                        <option value="arterial_ulcer">Arterial Ulcer</option>
                        <option value="diabetic_ulcer">Diabetic Ulcer</option>
                        <option value="surgical">Surgical</option>
                        <option value="traumatic">Traumatic</option>
                        <option value="skin_tear">Skin Tear</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Initial Stage</label
                    >
                    <select
                        v-model="woundForm.current_stage"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    >
                        <option value="stage_1">Stage 1</option>
                        <option value="stage_2">Stage 2</option>
                        <option value="stage_3">Stage 3</option>
                        <option value="stage_4">Stage 4</option>
                        <option value="unstageable">Unstageable</option>
                        <option value="deep_tissue">Deep Tissue</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Onset Date</label
                    >
                    <input
                        v-model="woundForm.onset_date"
                        type="date"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
            </div>
            <p v-if="woundError" class="text-red-600 dark:text-red-400 text-xs mb-2">
                {{ woundError }}
            </p>
            <div class="flex gap-2">
                <button
                    :disabled="savingWound"
                    class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                    @click="submitWound"
                >
                    {{ savingWound ? 'Saving...' : 'Save' }}
                </button>
                <button
                    class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                    @click="showAddWound = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- Open wounds -->
        <div
            v-if="openWounds.length === 0 && !showAddWound"
            class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm"
        >
            No open wounds.
        </div>
        <div class="space-y-3 mb-6">
            <div
                v-for="wound in openWounds"
                :key="wound.id"
                :class="[
                    'border rounded-lg overflow-hidden',
                    STAGE_COLORS[wound.current_stage ?? ''] ??
                        'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800',
                ]"
            >
                <div class="flex items-start gap-3 px-4 py-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{
                                wound.location
                            }}</span>
                            <span
                                class="text-xs bg-white/80 dark:bg-slate-900/60 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded capitalize"
                                >{{ wound.wound_type.replace('_', ' ') }}</span
                            >
                            <span
                                v-if="wound.current_stage"
                                class="text-xs font-medium text-gray-700 dark:text-slate-300 capitalize"
                                >{{ wound.current_stage.replace('_', ' ') }}</span
                            >
                        </div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                            Onset: {{ fmtDate(wound.onset_date) }} ·
                            {{ wound.assessments.length }} assessments
                        </div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <button
                            class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
                            @click="addAssessmentForId = addAssessmentForId === wound.id ? null : wound.id"
                        >
                            Assess
                        </button>
                        <button
                            class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-green-50 dark:hover:bg-green-950/30 transition-colors"
                            @click="closeWound(wound)"
                        >
                            Close
                        </button>
                    </div>
                </div>

                <!-- Assessment form -->
                <div
                    v-if="addAssessmentForId === wound.id"
                    class="border-t border-gray-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/30 px-4 py-3"
                >
                    <h4 class="text-xs font-semibold text-gray-700 dark:text-slate-300 mb-2">
                        New Assessment
                    </h4>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5"
                                >Length (cm)</label
                            >
                            <input
                                v-model="assessmentForm.length_cm"
                                type="number"
                                step="0.1"
                                class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700"
                            />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5"
                                >Width (cm)</label
                            >
                            <input
                                v-model="assessmentForm.width_cm"
                                type="number"
                                step="0.1"
                                class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700"
                            />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5"
                                >Depth (cm)</label
                            >
                            <input
                                v-model="assessmentForm.depth_cm"
                                type="number"
                                step="0.1"
                                class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700"
                            />
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5"
                            >Notes</label
                        >
                        <input
                            v-model="assessmentForm.notes"
                            type="text"
                            class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <p v-if="assessmentError" class="text-red-600 dark:text-red-400 text-xs mb-1">
                        {{ assessmentError }}
                    </p>
                    <div class="flex gap-2">
                        <button
                            :disabled="savingAssessment"
                            class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 transition-colors"
                            @click="submitAssessment(wound.id)"
                        >
                            {{ savingAssessment ? 'Saving...' : 'Save Assessment' }}
                        </button>
                        <button
                            class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded transition-colors"
                            @click="addAssessmentForId = null"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Healed wounds -->
        <div v-if="healedWounds.length > 0">
            <h3
                class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2"
            >
                Healed / Closed
            </h3>
            <div class="space-y-1">
                <div
                    v-for="wound in healedWounds"
                    :key="wound.id"
                    class="flex items-center gap-3 text-xs text-gray-500 dark:text-slate-400 px-4 py-2 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-lg"
                >
                    <span class="text-gray-600 dark:text-slate-300">{{ wound.location }}</span>
                    <span class="capitalize text-gray-400 dark:text-slate-500">{{
                        wound.wound_type.replace('_', ' ')
                    }}</span>
                    <span class="ml-auto">Closed: {{ fmtDate(wound.closed_date) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
