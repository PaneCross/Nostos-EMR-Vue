<script setup lang="ts">
// ─── RestraintsTab.vue ─────────────────────────────────────────────────────
// Physical + chemical restraint episodes with monitoring observations
// and IDT (Interdisciplinary Team) review tracking. Restraint use is
// a high-scrutiny event under 42 CFR §460 and the CMS PACE Audit
// Protocol: every episode requires periodic monitoring (every 4h)
// and an IDT review within 24h.
//
// Append-only: episodes are never deleted, only ended. Write access:
// primary_care, qa_compliance.
// ───────────────────────────────────────────────────────────────────────────
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'

interface Observation {
    id: number
    observed_at: string
    observed_by?: { first_name: string; last_name: string } | null
    skin_integrity: string | null
    circulation: string | null
    mental_status: string | null
    toileting_offered: boolean
    hydration_offered: boolean
    repositioning_done: boolean
    notes: string | null
}

interface Episode {
    id: number
    restraint_type: 'physical' | 'chemical' | 'both'
    initiated_at: string
    initiated_by: { id: number; first_name: string; last_name: string } | null
    ordered_by: { id: number; first_name: string; last_name: string } | null
    reason_text: string
    alternatives_tried_text: string | null
    medication_text: string | null
    monitoring_interval_min: number
    status: 'active' | 'discontinued' | 'expired'
    discontinued_at: string | null
    discontinued_by: { first_name: string; last_name: string } | null
    discontinuation_reason: string | null
    idt_review_date: string | null
    idt_reviewer: { first_name: string; last_name: string } | null
    outcome_text: string | null
    observations: Observation[]
    is_active: boolean
    is_chemical: boolean
    minutes_since_last_observation: number | null
    monitoring_overdue: boolean
    idt_review_overdue: boolean
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const episodes = ref<Episode[]>([])
const loading = ref(true)
const error = ref('')

const showNew = ref(false)
const newForm = reactive({
    restraint_type: 'physical' as 'physical' | 'chemical' | 'both',
    reason_text: '',
    alternatives_tried_text: '',
    ordering_provider_user_id: null as number | null,
    medication_text: '',
    monitoring_interval_min: 15,
})
const saving = ref(false)

const obsForm = reactive({
    skin_integrity:    '' as ''|'intact'|'reddened'|'broken'|'other',
    circulation:       '' as ''|'adequate'|'diminished'|'absent',
    mental_status:     '' as ''|'calm'|'agitated'|'sedated'|'unresponsive'|'other',
    toileting_offered: false,
    hydration_offered: false,
    repositioning_done:false,
    notes: '',
})
const activeObsEpisodeId = ref<number | null>(null)

const discForm = reactive({ discontinuation_reason: '' })
const activeDiscId = ref<number | null>(null)

const idtForm = reactive({ outcome_text: '' })
const activeIdtId = ref<number | null>(null)

async function load() {
    loading.value = true; error.value = ''
    try {
        const r = await axios.get(`/participants/${props.participant.id}/restraints`)
        episodes.value = r.data.episodes
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Failed to load restraint episodes.'
    } finally { loading.value = false }
}

async function create() {
    saving.value = true; error.value = ''
    try {
        const payload: any = {
            restraint_type:           newForm.restraint_type,
            reason_text:              newForm.reason_text,
            alternatives_tried_text:  newForm.alternatives_tried_text || null,
            monitoring_interval_min:  newForm.monitoring_interval_min,
        }
        if (newForm.restraint_type !== 'physical') {
            payload.ordering_provider_user_id = newForm.ordering_provider_user_id
            payload.medication_text = newForm.medication_text
        }
        await axios.post(`/participants/${props.participant.id}/restraints`, payload)
        showNew.value = false
        Object.assign(newForm, { restraint_type: 'physical', reason_text: '',
            alternatives_tried_text: '', ordering_provider_user_id: null,
            medication_text: '', monitoring_interval_min: 15 })
        await load()
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Failed to save episode.'
    } finally { saving.value = false }
}

async function recordObs(episodeId: number) {
    saving.value = true; error.value = ''
    try {
        const payload: any = {
            toileting_offered: obsForm.toileting_offered,
            hydration_offered: obsForm.hydration_offered,
            repositioning_done: obsForm.repositioning_done,
            notes: obsForm.notes || null,
        }
        if (obsForm.skin_integrity) payload.skin_integrity = obsForm.skin_integrity
        if (obsForm.circulation)    payload.circulation = obsForm.circulation
        if (obsForm.mental_status)  payload.mental_status = obsForm.mental_status
        await axios.post(`/participants/${props.participant.id}/restraints/${episodeId}/observations`, payload)
        activeObsEpisodeId.value = null
        Object.assign(obsForm, { skin_integrity: '', circulation: '', mental_status: '',
            toileting_offered: false, hydration_offered: false, repositioning_done: false, notes: '' })
        await load()
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Failed to save observation.'
    } finally { saving.value = false }
}

async function discontinue(episodeId: number) {
    if (!discForm.discontinuation_reason || discForm.discontinuation_reason.length < 5) {
        error.value = 'Discontinuation reason must be at least 5 characters.'
        return
    }
    saving.value = true; error.value = ''
    try {
        await axios.post(`/participants/${props.participant.id}/restraints/${episodeId}/discontinue`,
            { discontinuation_reason: discForm.discontinuation_reason })
        activeDiscId.value = null
        discForm.discontinuation_reason = ''
        await load()
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Failed to discontinue.'
    } finally { saving.value = false }
}

async function recordIdt(episodeId: number) {
    if (!idtForm.outcome_text || idtForm.outcome_text.length < 15) {
        error.value = 'IDT outcome text must be at least 15 characters.'
        return
    }
    saving.value = true; error.value = ''
    try {
        await axios.post(`/participants/${props.participant.id}/restraints/${episodeId}/idt-review`,
            { outcome_text: idtForm.outcome_text })
        activeIdtId.value = null
        idtForm.outcome_text = ''
        await load()
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Failed to record IDT review.'
    } finally { saving.value = false }
}

function fmt(ts: string | null): string {
    if (!ts) return '-'
    return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

const TYPE_CLASS: Record<string, string> = {
    physical: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    chemical: 'bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300',
    both:     'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
}
const STATUS_CLASS: Record<string, string> = {
    active:       'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    discontinued: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    expired:      'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300',
}

onMounted(load)
</script>

<template>
    <div class="p-6 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Restraint Episodes</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    42 CFR §460 + CMS PACE Audit: physical + chemical restraint documentation with monitoring + IDT review.
                </p>
            </div>
            <button @click="showNew = !showNew"
                class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                {{ showNew ? 'Cancel' : '+ New episode' }}
            </button>
        </div>

        <!-- New episode form -->
        <div v-if="showNew" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4 space-y-3">
            <div class="grid grid-cols-3 gap-3 text-sm">
                <label>
                    <span class="text-slate-600 dark:text-slate-400">Type</span>
                    <select v-model="newForm.restraint_type" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                        <option value="physical">Physical</option>
                        <option value="chemical">Chemical</option>
                        <option value="both">Both</option>
                    </select>
                </label>
                <label>
                    <span class="text-slate-600 dark:text-slate-400">Monitoring interval (min)</span>
                    <input type="number" v-model.number="newForm.monitoring_interval_min" min="5" max="240"
                        class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                </label>
                <label v-if="newForm.restraint_type !== 'physical'">
                    <span class="text-slate-600 dark:text-slate-400">Ordering provider user-id</span>
                    <input type="number" v-model.number="newForm.ordering_provider_user_id"
                        class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                </label>
            </div>
            <label class="text-sm block">
                <span class="text-slate-600 dark:text-slate-400">Reason (min 15 chars)</span>
                <textarea v-model="newForm.reason_text" rows="2" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
            </label>
            <label class="text-sm block">
                <span class="text-slate-600 dark:text-slate-400">Alternatives tried</span>
                <textarea v-model="newForm.alternatives_tried_text" rows="2" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
            </label>
            <label v-if="newForm.restraint_type !== 'physical'" class="text-sm block">
                <span class="text-slate-600 dark:text-slate-400">Medication (free text: name + dose)</span>
                <input v-model="newForm.medication_text" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
            </label>
            <div class="flex gap-2">
                <button @click="create" :disabled="saving || newForm.reason_text.length < 15"
                    class="text-xs px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 disabled:opacity-50">
                    {{ saving ? 'Saving...' : 'Initiate episode' }}
                </button>
            </div>
        </div>

        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

        <!-- Episodes -->
        <div v-if="loading" class="text-sm text-slate-500">Loading…</div>
        <div v-else-if="episodes.length === 0" class="text-sm italic text-slate-400 py-6 text-center">
            No restraint episodes on record.
        </div>

        <div v-else class="space-y-4">
            <div v-for="e in episodes" :key="e.id"
                class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4"
                :class="e.is_active && (e.monitoring_overdue || e.idt_review_overdue)
                        ? 'border-l-4 border-l-red-500' : ''">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span :class="['inline-flex px-2 py-0.5 rounded text-xs', TYPE_CLASS[e.restraint_type]]">{{ e.restraint_type }}</span>
                            <span :class="['inline-flex px-2 py-0.5 rounded text-xs', STATUS_CLASS[e.status]]">{{ e.status }}</span>
                            <span v-if="e.monitoring_overdue" class="text-xs px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">monitoring overdue</span>
                            <span v-if="e.idt_review_overdue" class="text-xs px-2 py-0.5 rounded bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200 font-medium">IDT overdue</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 ml-auto">Started {{ fmt(e.initiated_at) }}</span>
                        </div>
                        <p class="text-sm text-slate-800 dark:text-slate-200 mt-2 whitespace-pre-wrap">{{ e.reason_text }}</p>
                        <p v-if="e.alternatives_tried_text" class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            <span class="font-semibold">Alternatives:</span> {{ e.alternatives_tried_text }}
                        </p>
                        <p v-if="e.medication_text" class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            <span class="font-semibold">Medication:</span> {{ e.medication_text }}
                        </p>
                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 flex gap-3 flex-wrap">
                            <span>Monitoring every {{ e.monitoring_interval_min }} min</span>
                            <span v-if="e.is_active && e.minutes_since_last_observation !== null">Last obs: {{ e.minutes_since_last_observation }} min ago</span>
                            <span>Observations: {{ e.observations?.length ?? 0 }}</span>
                        </div>
                    </div>
                </div>

                <!-- Active controls -->
                <div v-if="e.is_active" class="mt-3 flex flex-wrap gap-2">
                    <button @click="activeObsEpisodeId = activeObsEpisodeId === e.id ? null : e.id"
                        class="text-xs px-2 py-1 border border-slate-300 dark:border-slate-600 rounded text-slate-600 dark:text-slate-300">
                        Record observation
                    </button>
                    <button @click="activeDiscId = activeDiscId === e.id ? null : e.id"
                        class="text-xs px-2 py-1 border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300 rounded">
                        Discontinue
                    </button>
                    <button v-if="!e.idt_review_date" @click="activeIdtId = activeIdtId === e.id ? null : e.id"
                        class="text-xs px-2 py-1 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300 rounded">
                        Record IDT review
                    </button>
                </div>

                <!-- Observation form -->
                <div v-if="activeObsEpisodeId === e.id" class="mt-3 border-t border-slate-200 dark:border-slate-700 pt-3 space-y-2">
                    <div class="grid grid-cols-3 gap-2 text-xs">
                        <label><span class="text-slate-500">Skin</span>
                            <select v-model="obsForm.skin_integrity" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs">
                                <option value="">-</option>
                                <option>intact</option><option>reddened</option><option>broken</option><option>other</option>
                            </select>
                        </label>
                        <label><span class="text-slate-500">Circulation</span>
                            <select v-model="obsForm.circulation" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs">
                                <option value="">-</option>
                                <option>adequate</option><option>diminished</option><option>absent</option>
                            </select>
                        </label>
                        <label><span class="text-slate-500">Mental status</span>
                            <select v-model="obsForm.mental_status" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs">
                                <option value="">-</option>
                                <option>calm</option><option>agitated</option><option>sedated</option><option>unresponsive</option><option>other</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex gap-3 text-xs">
                        <label class="flex items-center gap-1"><input type="checkbox" v-model="obsForm.toileting_offered" /> Toileting offered</label>
                        <label class="flex items-center gap-1"><input type="checkbox" v-model="obsForm.hydration_offered" /> Hydration offered</label>
                        <label class="flex items-center gap-1"><input type="checkbox" v-model="obsForm.repositioning_done" /> Repositioned</label>
                    </div>
                    <textarea v-model="obsForm.notes" rows="2" placeholder="Notes"
                        class="w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs"></textarea>
                    <button @click="recordObs(e.id)" :disabled="saving"
                        class="text-xs px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 disabled:opacity-50">Save observation</button>
                </div>

                <!-- Discontinue form -->
                <div v-if="activeDiscId === e.id" class="mt-3 border-t border-slate-200 dark:border-slate-700 pt-3 space-y-2">
                    <textarea v-model="discForm.discontinuation_reason" rows="2" placeholder="Reason for discontinuation"
                        class="w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs"></textarea>
                    <button @click="discontinue(e.id)" :disabled="saving"
                        class="text-xs px-3 py-1.5 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">Discontinue</button>
                </div>

                <!-- IDT review form -->
                <div v-if="activeIdtId === e.id" class="mt-3 border-t border-slate-200 dark:border-slate-700 pt-3 space-y-2">
                    <textarea v-model="idtForm.outcome_text" rows="3" placeholder="IDT review outcome + recommendations (min 15 chars)"
                        class="w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-xs"></textarea>
                    <button @click="recordIdt(e.id)" :disabled="saving"
                        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">Save IDT review</button>
                </div>

                <!-- Observation log -->
                <details v-if="(e.observations?.length ?? 0) > 0" class="mt-3 text-xs">
                    <summary class="cursor-pointer text-slate-500 dark:text-slate-400">
                        {{ e.observations?.length ?? 0 }} observation(s): click to expand
                    </summary>
                    <div class="mt-2 space-y-2">
                        <div v-for="o in e.observations ?? []" :key="o.id"
                            class="p-2 border border-slate-200 dark:border-slate-700 rounded">
                            <div class="flex items-start justify-between gap-2">
                                <div class="text-slate-600 dark:text-slate-300 flex flex-wrap gap-3">
                                    <span v-if="o.skin_integrity">Skin: {{ o.skin_integrity }}</span>
                                    <span v-if="o.circulation">Circ: {{ o.circulation }}</span>
                                    <span v-if="o.mental_status">MS: {{ o.mental_status }}</span>
                                    <span v-if="o.toileting_offered">✓ toilet</span>
                                    <span v-if="o.hydration_offered">✓ hydration</span>
                                    <span v-if="o.repositioning_done">✓ reposition</span>
                                </div>
                                <span class="text-slate-400">{{ fmt(o.observed_at) }}</span>
                            </div>
                            <p v-if="o.notes" class="text-slate-500 dark:text-slate-400 mt-1">{{ o.notes }}</p>
                        </div>
                    </div>
                </details>

                <!-- Disposition (discontinued + IDT outcome) -->
                <div v-if="!e.is_active" class="mt-3 text-xs text-slate-600 dark:text-slate-400 border-t border-slate-200 dark:border-slate-700 pt-2 space-y-1">
                    <p v-if="e.discontinued_at">
                        <span class="font-semibold">Discontinued:</span> {{ fmt(e.discontinued_at) }}: {{ e.discontinuation_reason }}
                    </p>
                    <p v-if="e.idt_review_date">
                        <span class="font-semibold">IDT review {{ e.idt_review_date }}:</span> {{ e.outcome_text }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
