<script setup lang="ts">
// ─── DataImports/Index.vue ───────────────────────────────────────────────────
// Phase 15-UI. CSV import wizard for participants / problems / allergies /
// medications. Upload → preview + error list → commit.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface DataImport {
    id: number
    entity: string
    status: string
    original_filename: string
    parsed_row_count: number
    committed_row_count: number
    error_row_count: number
    errors_json: any[] | null
    created_at: string
    committed_at: string | null
}

const props = defineProps<{
    imports: DataImport[]
    entities: string[]
    templates: Record<string, string[]>
}>()

const imports = ref<DataImport[]>([...props.imports])
const form = reactive({ entity: 'participants', file: null as File | null })
const preview = ref<any | null>(null)
const busy = ref(false)
const error = ref('')
const activeImportId = ref<number | null>(null)

function pickFile(e: Event) {
    form.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function upload() {
    if (!form.file) return
    busy.value = true; error.value = ''; preview.value = null
    try {
        const data = new FormData()
        data.append('entity', form.entity)
        data.append('file', form.file)
        const r = await axios.post('/data-imports', data, { headers: { 'Content-Type': 'multipart/form-data' } })
        imports.value = [r.data.import, ...imports.value]
        activeImportId.value = r.data.import.id
        preview.value = r.data.preview
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Upload failed.'
    } finally {
        busy.value = false
    }
}

async function commit(imp: DataImport) {
    if (!window.confirm(`Commit ${imp.parsed_row_count} rows from ${imp.original_filename}? This will insert data and cannot be undone.`)) return
    busy.value = true; error.value = ''
    try {
        const r = await axios.post(`/data-imports/${imp.id}/commit`)
        imports.value = imports.value.map(i => i.id === imp.id ? r.data.import : i)
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Commit failed.'
    } finally {
        busy.value = false
    }
}

function downloadTemplate(entity: string) {
    window.open(`/data-imports/template/${entity}`, '_blank')
}

const STATUS_CLASS: Record<string, string> = {
    staged:    'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    committed: 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    failed:    'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    cancelled: 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
}
</script>

<template>
    <AppShell title="Data Imports">
        <Head title="Data Imports" />
        <div class="max-w-5xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Data Imports</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    CSV import wizard for displacing an incumbent EMR. Upload a file, review the preview + errors, commit.
                </p>
            </div>

            <!-- Upload form -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 space-y-3">
                <div class="grid grid-cols-3 gap-3">
                    <label class="text-sm col-span-1">
                        <span class="text-slate-600 dark:text-slate-400">Entity</span>
                        <select v-model="form.entity" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="e in entities" :key="e" :value="e">{{ e }}</option>
                        </select>
                    </label>
                    <label class="text-sm col-span-2">
                        <span class="text-slate-600 dark:text-slate-400">CSV file</span>
                        <input type="file" accept=".csv,text/csv" @change="pickFile"
                            class="mt-1 w-full text-sm file:mr-3 file:px-3 file:py-1.5 file:rounded file:border-0 file:bg-blue-600 file:text-white" />
                    </label>
                </div>
                <div class="flex gap-2 items-center">
                    <button @click="upload" :disabled="busy || !form.file"
                        class="px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm disabled:opacity-50">
                        {{ busy ? 'Uploading...' : 'Upload + preview' }}
                    </button>
                    <button @click="downloadTemplate(form.entity)" class="text-xs text-slate-600 dark:text-slate-300 underline">
                        Download template CSV
                    </button>
                </div>
                <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
            </div>

            <!-- Preview -->
            <div v-if="preview" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 space-y-3">
                <div class="flex justify-between items-center">
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Preview: {{ preview.row_count }} row(s) parsed, {{ (preview.errors ?? []).length }} error(s)
                    </div>
                    <button v-if="activeImportId" @click="commit(imports.find(i => i.id === activeImportId)!)" :disabled="busy"
                        class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">
                        Commit
                    </button>
                </div>
                <div v-if="preview.errors.length > 0" class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded p-3 text-xs space-y-1">
                    <div v-for="(err, i) in preview.errors.slice(0, 20)" :key="i" class="text-red-700 dark:text-red-300">
                        Row {{ err.row }}: {{ err.message }}
                    </div>
                    <div v-if="preview.errors.length > 20" class="text-red-600">...and {{ preview.errors.length - 20 }} more</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th v-for="c in preview.headers" :key="c" class="px-2 py-1 text-left text-slate-500">{{ c }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in preview.preview" :key="i" class="border-t border-gray-100 dark:border-slate-700">
                                <td v-for="c in preview.headers" :key="c" class="px-2 py-1 text-slate-700 dark:text-slate-300">{{ row[c] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- History -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">File</th>
                            <th class="px-3 py-2 text-left">Entity</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-right">Rows</th>
                            <th class="px-3 py-2 text-right">Errors</th>
                            <th class="px-3 py-2 text-left">Created</th>
                            <th class="px-3 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="imports.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No imports yet.</td>
                        </tr>
                        <tr v-for="imp in imports" :key="imp.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-300 truncate max-w-[280px]">{{ imp.original_filename }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ imp.entity }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 text-xs rounded', STATUS_CLASS[imp.status] ?? 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300']">
                                    {{ imp.status }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-slate-500">{{ imp.parsed_row_count }} / {{ imp.committed_row_count }}</td>
                            <td class="px-3 py-2 text-right text-slate-500">{{ imp.error_row_count }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ imp.created_at }}</td>
                            <td class="px-3 py-2 text-right">
                                <button v-if="imp.status === 'staged'" @click="commit(imp)" :disabled="busy"
                                    class="text-xs text-emerald-600 dark:text-emerald-400">Commit</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
