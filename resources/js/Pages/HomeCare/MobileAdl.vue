<script setup lang="ts">
// ─── HomeCare/MobileAdl.vue ──────────────────────────────────────────────────
// Phase 15.5 (MVP roadmap). Tablet-optimized ADL + home-visit documentation
// page for home care staff. Large tap targets, minimal chrome, sticky Save
// button, fullscreen-friendly. Reuses the existing ADL endpoints so no
// backend changes are required.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface Participant {
    id: number
    first_name: string
    last_name: string
    mrn: string
    dob: string
}

const props = defineProps<{
    participants: Participant[]  // assigned home-care participants for today
}>()

const selected = ref<Participant | null>(null)
const saving = ref(false)
const savedAt = ref<string | null>(null)
const error = ref('')

const ADL_DOMAINS = [
    { key: 'bathing',     label: 'Bathing' },
    { key: 'dressing',    label: 'Dressing' },
    { key: 'toileting',   label: 'Toileting' },
    { key: 'transferring',label: 'Transferring' },
    { key: 'continence',  label: 'Continence' },
    { key: 'feeding',     label: 'Feeding' },
] as const

const form = reactive<Record<string, 'independent' | 'assist' | 'dependent' | ''>>({
    bathing: '', dressing: '', toileting: '',
    transferring: '', continence: '', feeding: '',
})
const visitNotes = ref('')

function selectParticipant(p: Participant) {
    selected.value = p
    savedAt.value = null
    error.value = ''
    Object.keys(form).forEach(k => (form as any)[k] = '')
    visitNotes.value = ''
}

async function save() {
    if (!selected.value) return
    saving.value = true
    error.value = ''
    try {
        const r = await axios.post(`/participants/${selected.value.id}/adl-records`, {
            responses: { ...form },
            notes: visitNotes.value,
            visit_type: 'home',
            source: 'mobile_tablet',
        })
        savedAt.value = new Date().toLocaleTimeString()
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Save failed. Check connection.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AppShell title="Home-Care ADL (Mobile)">
        <Head title="Mobile ADL" />

        <div class="max-w-3xl mx-auto p-3 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Home-Care ADL</h1>
                <Link href="/dashboard/home-care" class="text-sm text-blue-600 dark:text-blue-400">Back</Link>
            </div>

            <!-- Participant picker (big tap targets) -->
            <div v-if="!selected" class="space-y-2">
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-2">Select a participant for today's visit:</p>
                <button
                    v-for="p in participants"
                    :key="p.id"
                    @click="selectParticipant(p)"
                    class="w-full flex items-center justify-between px-5 py-4 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl text-left shadow-sm hover:border-blue-500 active:bg-slate-50 dark:active:bg-slate-700 text-lg"
                >
                    <div>
                        <div class="font-semibold text-slate-900 dark:text-slate-100">{{ p.first_name }} {{ p.last_name }}</div>
                        <div class="text-sm text-slate-500 dark:text-slate-400">MRN {{ p.mrn }} &middot; DOB {{ p.dob }}</div>
                    </div>
                    <span class="text-blue-600 dark:text-blue-400">›</span>
                </button>
                <p v-if="participants.length === 0" class="italic text-slate-400 text-center py-8">No participants assigned today.</p>
            </div>

            <!-- ADL form -->
            <div v-else class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                            {{ selected.first_name }} {{ selected.last_name }}
                        </div>
                        <div class="text-sm text-slate-500 dark:text-slate-400">MRN {{ selected.mrn }}</div>
                    </div>
                    <button @click="selected = null" class="text-sm text-blue-600 dark:text-blue-400">Change</button>
                </div>

                <div class="space-y-3">
                    <div v-for="d in ADL_DOMAINS" :key="d.key"
                        class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <div class="font-medium text-slate-900 dark:text-slate-100 mb-2">{{ d.label }}</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button v-for="(lbl, val) in { independent: 'Independent', assist: 'Assist', dependent: 'Dependent' }"
                                :key="val"
                                @click="(form as any)[d.key] = val"
                                :class="['py-3 rounded-lg text-base font-medium border transition',
                                    (form as any)[d.key] === val
                                      ? 'bg-blue-600 text-white border-blue-700'
                                      : 'bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200']">
                                {{ lbl }}
                            </button>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <label class="font-medium text-slate-900 dark:text-slate-100 mb-2 block">Visit notes</label>
                        <textarea v-model="visitNotes" rows="4"
                            class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-base p-3"
                            placeholder="Observations, concerns, caregiver communication..."></textarea>
                    </div>
                </div>

                <div v-if="error" class="bg-red-50 dark:bg-red-950/40 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg px-4 py-3 text-sm">
                    {{ error }}
                </div>

                <div v-if="savedAt" class="bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm">
                    Saved at {{ savedAt }}. Go home and rest.
                </div>

                <!-- Sticky save bar -->
                <div class="sticky bottom-0 -mx-3 sm:-mx-6 p-3 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700">
                    <button @click="save" :disabled="saving"
                        class="w-full py-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-50 text-white text-lg font-semibold rounded-xl shadow-md">
                        {{ saving ? 'Saving...' : 'Save ADL' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
