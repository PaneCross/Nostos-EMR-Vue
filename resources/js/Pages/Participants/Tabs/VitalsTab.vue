<script setup lang="ts">
// ─── VitalsTab.vue ────────────────────────────────────────────────────────────
// Vitals history table with out-of-range highlighting. Record new vitals form
// includes blood glucose with timing field (QW-02) and auto-calculated BMI
// (QW-01). Most recent reading shown at top. Append-only — no edit/delete.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Vital {
    id: number
    recorded_at: string
    bp_systolic: number | null
    bp_diastolic: number | null
    pulse: number | null
    temperature_f: number | null
    respiratory_rate: number | null
    o2_saturation: number | null
    weight_lbs: number | null
    height_in: number | null
    pain_score: number | null
    blood_glucose: number | null
    blood_glucose_timing: string | null
    bmi: number | null
    notes: string | null
    recorded_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    vitals: Vital[]
}>()

const vitals = ref<Vital[]>(props.vitals)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

const GLUCOSE_TIMING_LABELS: Record<string, string> = {
    before_meal: 'Before Meal',
    after_meal: 'After Meal',
    fasting: 'Fasting',
    random: 'Random',
    bedtime: 'Bedtime',
}

const form = ref({
    recorded_at: new Date().toISOString().slice(0, 16),
    bp_systolic: '',
    bp_diastolic: '',
    pulse: '',
    temperature_f: '',
    respiratory_rate: '',
    o2_saturation: '',
    weight_lbs: '',
    height_in: '',
    pain_score: '',
    blood_glucose: '',
    blood_glucose_timing: '',
    notes: '',
})

// O2 sat below 95% is highlighted as low
function o2Color(val: number | null) {
    if (!val) return ''
    return val < 95 ? 'text-red-600 dark:text-red-400 font-semibold' : ''
}

// Pain score 7+ highlighted
function painColor(val: number | null) {
    if (!val) return ''
    return val >= 7 ? 'text-red-600 dark:text-red-400 font-semibold' : ''
}

function fmtDateTime(val: string): string {
    const d = new Date(val)
    return d.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
}

async function submit() {
    saving.value = true
    error.value = ''
    const payload: Record<string, unknown> = { recorded_at: form.value.recorded_at }
    const numFields = [
        'bp_systolic',
        'bp_diastolic',
        'pulse',
        'temperature_f',
        'respiratory_rate',
        'o2_saturation',
        'weight_lbs',
        'height_in',
        'pain_score',
        'blood_glucose',
    ] as const
    numFields.forEach((f) => {
        const v = form.value[f]
        payload[f] = v !== '' ? parseFloat(v as string) : null
    })
    payload.blood_glucose_timing = form.value.blood_glucose_timing || null
    payload.notes = form.value.notes || null

    try {
        const res = await axios.post(`/participants/${props.participant.id}/vitals`, payload)
        vitals.value.unshift(res.data)
        showAddForm.value = false
        form.value = {
            recorded_at: new Date().toISOString().slice(0, 16),
            bp_systolic: '',
            bp_diastolic: '',
            pulse: '',
            temperature_f: '',
            respiratory_rate: '',
            o2_saturation: '',
            weight_lbs: '',
            height_in: '',
            pain_score: '',
            blood_glucose: '',
            blood_glucose_timing: '',
            notes: '',
        }
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        error.value = e.response?.data?.message ?? 'Failed to save vitals.'
        saving.value = false
    }
}
</script>

<template>
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Vitals</h2>
            <button
                class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                @click="showAddForm = !showAddForm"
            >
                <PlusIcon class="w-3 h-3" />
                Record Vitals
            </button>
        </div>

        <!-- Record vitals form -->
        <div
            v-if="showAddForm"
            class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4"
        >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">
                Record Vitals
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                <div class="col-span-2 sm:col-span-1">
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Recorded At</label
                    >
                    <input
                        v-model="form.recorded_at"
                        type="datetime-local"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >BP Systolic</label
                    >
                    <input
                        v-model="form.bp_systolic"
                        type="number"
                        placeholder="mmHg"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >BP Diastolic</label
                    >
                    <input
                        v-model="form.bp_diastolic"
                        type="number"
                        placeholder="mmHg"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Pulse</label
                    >
                    <input
                        v-model="form.pulse"
                        type="number"
                        placeholder="bpm"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Temp (F)</label
                    >
                    <input
                        v-model="form.temperature_f"
                        type="number"
                        step="0.1"
                        placeholder="F"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >O2 Sat %</label
                    >
                    <input
                        v-model="form.o2_saturation"
                        type="number"
                        placeholder="%"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Weight (lbs)</label
                    >
                    <input
                        v-model="form.weight_lbs"
                        type="number"
                        step="0.1"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Height (in)</label
                    >
                    <input
                        v-model="form.height_in"
                        type="number"
                        step="0.1"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Pain Score (0-10)</label
                    >
                    <input
                        v-model="form.pain_score"
                        type="number"
                        min="0"
                        max="10"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Blood Glucose (mg/dL)</label
                    >
                    <input
                        v-model="form.blood_glucose"
                        type="number"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >Glucose Timing</label
                    >
                    <select
                        v-model="form.blood_glucose_timing"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                    >
                        <option value="">- not set -</option>
                        <option
                            v-for="(label, key) in GLUCOSE_TIMING_LABELS"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                    >Notes</label
                >
                <input
                    v-model="form.notes"
                    type="text"
                    placeholder="Optional"
                    class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                />
            </div>
            <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
            <div class="flex gap-2">
                <button
                    :disabled="saving"
                    class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                    @click="submit"
                >
                    {{ saving ? 'Saving...' : 'Save Vitals' }}
                </button>
                <button
                    class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                    @click="showAddForm = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- Vitals table -->
        <div
            v-if="vitals.length === 0"
            class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm"
        >
            No vitals recorded.
        </div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                        <th
                            class="text-left px-3 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Date/Time
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            BP
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Pulse
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Temp
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            O2%
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Wt (lbs)
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            BMI
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Pain
                        </th>
                        <th
                            class="text-center px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            Glucose
                        </th>
                        <th
                            class="text-left px-2 py-2 font-semibold text-gray-600 dark:text-slate-300 border-b border-gray-200 dark:border-slate-700"
                        >
                            By
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="vital in vitals"
                        :key="vital.id"
                        class="border-b border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/30"
                    >
                        <td class="px-3 py-2 text-gray-700 dark:text-slate-300">
                            {{ fmtDateTime(vital.recorded_at) }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{
                                vital.bp_systolic
                                    ? `${vital.bp_systolic}/${vital.bp_diastolic}`
                                    : '-'
                            }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{ vital.pulse ?? '-' }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{ vital.temperature_f ?? '-' }}
                        </td>
                        <td :class="['text-center px-2 py-2', o2Color(vital.o2_saturation)]">
                            {{ vital.o2_saturation ?? '-' }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{ vital.weight_lbs ?? '-' }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{ vital.bmi ?? '-' }}
                        </td>
                        <td :class="['text-center px-2 py-2', painColor(vital.pain_score)]">
                            {{ vital.pain_score ?? '-' }}
                        </td>
                        <td class="text-center px-2 py-2 text-gray-800 dark:text-slate-200">
                            {{ vital.blood_glucose ?? '-' }}
                            <span
                                v-if="vital.blood_glucose_timing"
                                class="text-gray-400 dark:text-slate-500 text-xs ml-1"
                                >({{
                                    GLUCOSE_TIMING_LABELS[vital.blood_glucose_timing] ??
                                    vital.blood_glucose_timing
                                }})</span
                            >
                        </td>
                        <td class="px-2 py-2 text-gray-500 dark:text-slate-400">
                            {{
                                vital.recorded_by
                                    ? `${vital.recorded_by.first_name[0]}. ${vital.recorded_by.last_name}`
                                    : '-'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
