<script setup lang="ts">
// ─── Formulary/Index.vue ─────────────────────────────────────────────────────
// Phase 15-UI. PACE formulary catalog with tier management + pending coverage
// determinations queue for pharmacy.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface Entry {
    id: number
    drug_name: string
    generic_name: string | null
    rxnorm_code: string | null
    tier: number
    prior_authorization_required: boolean
    quantity_limit: boolean
    quantity_limit_text: string | null
    step_therapy_required: boolean
    notes: string | null
    is_active: boolean
}

interface Determination {
    id: number
    drug_name: string
    determination_type: string
    status: string
    requested_at: string
    participant: { id: number; first_name: string; last_name: string; mrn: string } | null
    clinical_justification: string | null
}

const props = defineProps<{
    entries: Entry[]
    pendingDeterminations: Determination[]
    canEdit: boolean
}>()

const entries = ref<Entry[]>([...props.entries])
const pending = ref<Determination[]>([...props.pendingDeterminations])
const search = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)
const busy = ref(false)
const error = ref('')

const form = reactive({
    id: 0 as number | 0,
    drug_name: '', generic_name: '', rxnorm_code: '',
    tier: 1,
    prior_authorization_required: false,
    quantity_limit: false, quantity_limit_text: '',
    step_therapy_required: false,
    notes: '',
})

const filtered = computed(() => {
    const s = search.value.trim().toLowerCase()
    if (!s) return entries.value
    return entries.value.filter(e =>
        e.drug_name.toLowerCase().includes(s)
        || (e.generic_name ?? '').toLowerCase().includes(s)
        || (e.rxnorm_code ?? '').includes(s)
    )
})

const TIER_CLASS: Record<number, string> = {
    1: 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    2: 'bg-lime-100 dark:bg-lime-900/50 text-lime-700 dark:text-lime-300',
    3: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    4: 'bg-orange-100 dark:bg-orange-900/50 text-orange-700 dark:text-orange-300',
    5: 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
}

function resetForm() {
    form.id = 0; form.drug_name = ''; form.generic_name = ''; form.rxnorm_code = ''
    form.tier = 1; form.prior_authorization_required = false
    form.quantity_limit = false; form.quantity_limit_text = ''
    form.step_therapy_required = false; form.notes = ''
    editingId.value = null
}

function edit(e: Entry) {
    showForm.value = true; editingId.value = e.id
    Object.assign(form, e)
    form.id = e.id
}

async function save() {
    busy.value = true; error.value = ''
    try {
        const payload = {
            drug_name: form.drug_name,
            generic_name: form.generic_name || null,
            rxnorm_code: form.rxnorm_code || null,
            tier: form.tier,
            prior_authorization_required: form.prior_authorization_required,
            quantity_limit: form.quantity_limit,
            quantity_limit_text: form.quantity_limit_text || null,
            step_therapy_required: form.step_therapy_required,
            notes: form.notes || null,
        }
        if (editingId.value) {
            const r = await axios.put(`/formulary/${editingId.value}`, payload)
            entries.value = entries.value.map(e => e.id === editingId.value ? r.data.entry : e)
        } else {
            const r = await axios.post('/formulary', payload)
            entries.value = [r.data.entry, ...entries.value]
        }
        showForm.value = false; resetForm()
    } catch (e: any) { error.value = e?.response?.data?.message ?? 'Save failed.' }
    finally { busy.value = false }
}
</script>

<template>
    <AppShell title="Formulary">
        <Head title="Formulary" />
        <div class="max-w-6xl mx-auto p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Formulary</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">PACE capitated drug list, tiers, prior-auth + step-therapy flags.</p>
                </div>
                <button v-if="canEdit" @click="() => { resetForm(); showForm = !showForm }"
                    class="px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                    {{ showForm ? 'Cancel' : '+ Add entry' }}
                </button>
            </div>

            <div class="flex gap-3 items-center">
                <input v-model="search" placeholder="Search drug or generic name..."
                    class="flex-1 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                <div class="text-xs text-slate-500">{{ filtered.length }} of {{ entries.length }}</div>
            </div>

            <!-- Form -->
            <div v-if="showForm" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 space-y-3">
                <div class="grid grid-cols-3 gap-3">
                    <label class="text-sm col-span-2">
                        <span class="text-slate-600 dark:text-slate-400">Drug name</span>
                        <input v-model="form.drug_name" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Tier</span>
                        <select v-model.number="form.tier" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="t in [1,2,3,4,5]" :key="t" :value="t">Tier {{ t }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Generic name</span>
                        <input v-model="form.generic_name" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">RxNorm code</span>
                        <input v-model="form.rxnorm_code" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                </div>
                <div class="flex gap-4 flex-wrap text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" v-model="form.prior_authorization_required" /> Prior authorization required
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" v-model="form.quantity_limit" /> Quantity limit
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" v-model="form.step_therapy_required" /> Step therapy required
                    </label>
                </div>
                <label v-if="form.quantity_limit" class="text-sm block">
                    <span class="text-slate-600 dark:text-slate-400">Quantity limit text</span>
                    <input v-model="form.quantity_limit_text" placeholder="e.g., 30 tablets / 30 days"
                        class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                </label>
                <label class="text-sm block">
                    <span class="text-slate-600 dark:text-slate-400">Notes</span>
                    <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                </label>
                <button @click="save" :disabled="busy || !form.drug_name"
                    class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">
                    {{ editingId ? 'Update' : 'Save' }}
                </button>
                <span v-if="error" class="ml-3 text-sm text-red-600 dark:text-red-400">{{ error }}</span>
            </div>

            <!-- Entries table -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Drug</th>
                            <th class="px-3 py-2 text-left">Generic</th>
                            <th class="px-3 py-2 text-center">Tier</th>
                            <th class="px-3 py-2 text-left">Restrictions</th>
                            <th class="px-3 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-slate-400">No formulary entries match.</td>
                        </tr>
                        <tr v-for="e in filtered" :key="e.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2 text-slate-900 dark:text-slate-100">
                                {{ e.drug_name }}
                                <span v-if="e.rxnorm_code" class="text-xs text-slate-400 ml-1">({{ e.rxnorm_code }})</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ e.generic_name ?? '' }}</td>
                            <td class="px-3 py-2 text-center">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', TIER_CLASS[e.tier]]">T{{ e.tier }}</span>
                            </td>
                            <td class="px-3 py-2 space-x-1">
                                <span v-if="e.prior_authorization_required" class="text-xs px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">PA</span>
                                <span v-if="e.quantity_limit" class="text-xs px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300">QL</span>
                                <span v-if="e.step_therapy_required" class="text-xs px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300">ST</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button v-if="canEdit" @click="edit(e)" class="text-xs text-blue-600 dark:text-blue-400">Edit</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pending coverage determinations -->
            <div v-if="pending.length > 0" class="bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-700 rounded-xl overflow-hidden">
                <div class="px-4 py-2 bg-amber-50 dark:bg-amber-950/40 text-sm font-semibold text-amber-700 dark:text-amber-300">
                    Pending coverage determinations ({{ pending.length }})
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500">
                        <tr><th class="px-3 py-2 text-left">Participant</th><th class="px-3 py-2 text-left">Drug</th><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Requested</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in pending" :key="d.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-300">
                                <template v-if="d.participant">{{ d.participant.first_name }} {{ d.participant.last_name }}</template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ d.drug_name }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-400">{{ d.determination_type.replace(/_/g, ' ') }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ d.requested_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
