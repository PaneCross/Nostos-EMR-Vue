<script setup lang="ts">
// ─── Reports/CustomBuilder.vue ───────────────────────────────────────────────
// Phase 15-UI. Ad-hoc custom report builder (Phase 15.3). Distinct from the
// existing canned-report catalog at /reports. Users pick entity + filters +
// columns, save, run inline, download CSV.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface ReportDef {
    id: number
    name: string
    entity: string
    filters: any[] | null
    columns: string[] | null
    is_shared: boolean
    last_run_at: string | null
    updated_at: string
}

const props = defineProps<{ reports: ReportDef[]; entities: string[] }>()

const reports    = ref<ReportDef[]>([...props.reports])
const showBuilder= ref(false)
const runResult  = ref<{ columns: string[]; rows: any[]; total: number } | null>(null)
const runningId  = ref<number | null>(null)
const busy       = ref(false)
const error      = ref('')

const FIELD_OPTIONS: Record<string, string[]> = {
    participants: ['id', 'mrn', 'first_name', 'last_name', 'dob', 'gender',
        'enrollment_status', 'enrollment_date', 'disenrollment_date', 'primary_language', 'site_id'],
    medications:  ['id', 'participant_id', 'drug_name', 'rxnorm_code', 'status',
        'is_prn', 'is_controlled', 'controlled_schedule', 'prescribed_date', 'start_date', 'end_date'],
    grievances:   ['id', 'participant_id', 'category', 'priority', 'status',
        'filed_at', 'resolution_date', 'cms_reportable'],
    appointments: ['id', 'participant_id', 'provider_user_id', 'appointment_type',
        'status', 'scheduled_start', 'scheduled_end'],
    incidents:    ['id', 'participant_id', 'incident_type', 'severity', 'occurred_at', 'status'],
    care_plans:   ['id', 'participant_id', 'version', 'status', 'effective_date', 'review_due_date'],
}
const OPS = ['=', '!=', '<', '<=', '>', '>=', 'like', 'in', 'is_null', 'not_null']

const form = reactive<{ name: string; entity: string; filters: any[]; columns: string[]; is_shared: boolean }>({
    name: '', entity: 'participants', filters: [], columns: [], is_shared: false,
})
const availableFields = computed(() => FIELD_OPTIONS[form.entity] ?? [])

function addFilter() { form.filters.push({ field: availableFields.value[0] ?? '', op: '=', value: '' }) }
function removeFilter(i: number) { form.filters.splice(i, 1) }
function toggleColumn(f: string) {
    const i = form.columns.indexOf(f)
    if (i >= 0) form.columns.splice(i, 1); else form.columns.push(f)
}

async function save() {
    busy.value = true; error.value = ''
    try {
        const r = await axios.post('/reports', {
            name: form.name, entity: form.entity, filters: form.filters,
            columns: form.columns.length ? form.columns : null, is_shared: form.is_shared,
        })
        reports.value = [r.data.report, ...reports.value]
        showBuilder.value = false
        form.name = ''; form.filters = []; form.columns = []
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Save failed.'
    } finally {
        busy.value = false
    }
}

async function run(def: ReportDef) {
    runningId.value = def.id; runResult.value = null; error.value = ''
    try {
        const r = await axios.post(`/reports/${def.id}/run`)
        runResult.value = r.data
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Run failed.'
    } finally {
        runningId.value = null
    }
}

function download(def: ReportDef) {
    window.open(`/reports/${def.id}/download`, '_blank')
}

async function remove(def: ReportDef) {
    if (!window.confirm(`Delete "${def.name}"?`)) return
    await axios.delete(`/reports/${def.id}`)
    reports.value = reports.value.filter(r => r.id !== def.id)
    if (runningId.value === def.id) runResult.value = null
}
</script>

<template>
    <AppShell title="Custom Report Builder">
        <Head title="Custom Reports" />
        <div class="max-w-6xl mx-auto p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Custom Report Builder</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Build, save, run, and export ad-hoc reports across 6 entity types.</p>
                </div>
                <button @click="showBuilder = !showBuilder"
                    class="px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                    {{ showBuilder ? 'Cancel' : '+ New report' }}
                </button>
            </div>

            <div v-if="showBuilder" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Name</span>
                        <input v-model="form.name" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Entity</span>
                        <select v-model="form.entity" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="e in entities" :key="e" :value="e">{{ e.replace(/_/g, ' ') }}</option>
                        </select>
                    </label>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Filters</span>
                        <button @click="addFilter" class="text-xs text-blue-600 dark:text-blue-400">+ add filter</button>
                    </div>
                    <div v-for="(f, i) in form.filters" :key="i" class="grid grid-cols-12 gap-2 mt-2 items-center">
                        <select v-model="f.field" class="col-span-4 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="ff in availableFields" :key="ff" :value="ff">{{ ff }}</option>
                        </select>
                        <select v-model="f.op" class="col-span-3 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="o in OPS" :key="o" :value="o">{{ o }}</option>
                        </select>
                        <input v-model="f.value" class="col-span-4 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                            :disabled="['is_null','not_null'].includes(f.op)" />
                        <button @click="removeFilter(i)" class="col-span-1 text-red-600 dark:text-red-400 text-sm">✕</button>
                    </div>
                </div>

                <div>
                    <span class="text-sm text-slate-600 dark:text-slate-400">Columns (empty = all allowed)</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <button v-for="f in availableFields" :key="f"
                            @click="toggleColumn(f)"
                            :class="['text-xs px-2 py-1 rounded border',
                                form.columns.includes(f)
                                  ? 'bg-blue-600 text-white border-blue-700'
                                  : 'bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300']">
                            {{ f }}
                        </button>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" v-model="form.is_shared" />
                    <span>Share with all users in this tenant</span>
                </label>

                <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

                <button @click="save" :disabled="busy || !form.name"
                    class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">
                    {{ busy ? 'Saving...' : 'Save report' }}
                </button>
            </div>

            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Entity</th>
                            <th class="px-3 py-2 text-left">Filters</th>
                            <th class="px-3 py-2 text-left">Last run</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="reports.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-slate-400">No saved reports yet.</td>
                        </tr>
                        <tr v-for="def in reports" :key="def.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2 text-slate-900 dark:text-slate-100">{{ def.name }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ def.entity.replace(/_/g, ' ') }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ (def.filters ?? []).length }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ def.last_run_at ?? '-' }}</td>
                            <td class="px-3 py-2 text-right space-x-2">
                                <button @click="run(def)" :disabled="runningId === def.id" class="text-xs text-blue-600 dark:text-blue-400">
                                    {{ runningId === def.id ? 'Running...' : 'Run' }}
                                </button>
                                <button @click="download(def)" class="text-xs text-slate-600 dark:text-slate-300">CSV</button>
                                <button @click="remove(def)" class="text-xs text-red-600 dark:text-red-400">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="runResult" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 mb-2">{{ runResult.total }} row(s)</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th v-for="c in runResult.columns" :key="c" class="px-2 py-1 text-left text-slate-500">{{ c }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in runResult.rows" :key="i" class="border-t border-gray-100 dark:border-slate-700">
                                <td v-for="c in runResult.columns" :key="c" class="px-2 py-1 text-slate-700 dark:text-slate-300">{{ row[c] ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>
