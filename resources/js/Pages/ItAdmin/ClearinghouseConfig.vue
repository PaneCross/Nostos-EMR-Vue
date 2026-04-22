<script setup lang="ts">
// ─── ItAdmin/ClearinghouseConfig.vue ─────────────────────────────────────────
// Phase 12 (MVP roadmap). IT-admin-only page for configuring the claims
// clearinghouse adapter. Default is "No vendor — manual upload"; activating
// a real adapter requires a signed trading-partner agreement. Honest-label
// banner at the top makes the status explicit.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface ClearinghouseCfg {
    id: number
    adapter: string
    display_name: string
    environment: string
    submitter_id: string | null
    receiver_id: string | null
    endpoint_url: string | null
    submission_timeout_s: number
    max_retries: number
    retry_backoff_s: number
    notes: string | null
    is_active: boolean
    last_successful_at: string | null
    last_failed_at: string | null
    last_error: string | null
}

const props = defineProps<{
    configs: ClearinghouseCfg[]
    availableAdapters: Record<string, string>
    environments: string[]
    honestLabel: string
}>()

const configs = ref<ClearinghouseCfg[]>([...props.configs])
const showForm = ref(false)

const form = reactive({
    adapter: 'null_gateway',
    display_name: 'Manual upload (default)',
    environment: 'sandbox',
    submitter_id: '',
    receiver_id: '',
    endpoint_url: '',
    submission_timeout_s: 60,
    max_retries: 3,
    retry_backoff_s: 30,
    notes: '',
    is_active: false,
})

const saving = ref(false)
const saveError = ref('')

async function save() {
    saving.value = true
    saveError.value = ''
    try {
        const r = await axios.post('/it-admin/clearinghouse-config', form)
        configs.value = [r.data.config, ...configs.value]
        showForm.value = false
    } catch (e: any) {
        saveError.value = e?.response?.data?.message || 'Save failed.'
    } finally {
        saving.value = false
    }
}

async function toggleActive(cfg: ClearinghouseCfg) {
    const r = await axios.put(`/it-admin/clearinghouse-config/${cfg.id}`, { is_active: !cfg.is_active })
    configs.value = configs.value.map(c => c.id === cfg.id ? r.data.config : (c.is_active ? { ...c, is_active: false } : c))
}

async function runHealthCheck(cfg: ClearinghouseCfg) {
    const r = await axios.post(`/it-admin/clearinghouse-config/${cfg.id}/health-check`)
    window.alert(`[${cfg.adapter}] ${r.data.ok ? 'OK' : 'NOT WIRED'} — ${r.data.message}`)
}
</script>

<template>
    <AppShell title="Clearinghouse Configuration">
        <Head title="Clearinghouse Configuration" />

        <div class="max-w-5xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Clearinghouse Configuration</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Per-tenant claims-clearinghouse adapter settings. IT admin only.</p>
            </div>

            <div class="bg-amber-50 dark:bg-amber-900/40 border border-amber-200 dark:border-amber-800 rounded-lg p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ honestLabel }}
            </div>

            <div class="flex justify-end">
                <button @click="showForm = !showForm"
                    class="px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                    {{ showForm ? 'Cancel' : 'Add config' }}
                </button>
            </div>

            <div v-if="showForm" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Adapter</span>
                        <select v-model="form.adapter" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="(label, key) in availableAdapters" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Display name</span>
                        <input v-model="form.display_name" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Environment</span>
                        <select v-model="form.environment" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="e in environments" :key="e" :value="e">{{ e }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Submitter ID</span>
                        <input v-model="form.submitter_id" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Receiver ID</span>
                        <input v-model="form.receiver_id" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Endpoint URL</span>
                        <input v-model="form.endpoint_url" placeholder="https://..." class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                </div>
                <label class="text-sm block">
                    <span class="text-slate-600 dark:text-slate-400">Notes</span>
                    <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                </label>
                <label class="text-sm flex gap-2 items-center">
                    <input type="checkbox" v-model="form.is_active" />
                    <span>Activate this config (deactivates any other active config)</span>
                </label>
                <div class="flex items-center gap-3">
                    <button @click="save" :disabled="saving" class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">
                        {{ saving ? 'Saving...' : 'Save' }}
                    </button>
                    <span v-if="saveError" class="text-xs text-red-600 dark:text-red-400">{{ saveError }}</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Adapter</th>
                            <th class="px-3 py-2 text-left">Display</th>
                            <th class="px-3 py-2 text-left">Env</th>
                            <th class="px-3 py-2 text-left">Active</th>
                            <th class="px-3 py-2 text-left">Last success</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="configs.length === 0">
                            <td colspan="6" class="px-3 py-6 text-center text-slate-400">
                                No configuration rows yet. The tenant is using the default "No vendor — manual upload" adapter.
                            </td>
                        </tr>
                        <tr v-for="cfg in configs" :key="cfg.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">{{ availableAdapters[cfg.adapter] ?? cfg.adapter }}</td>
                            <td class="px-3 py-2">{{ cfg.display_name }}</td>
                            <td class="px-3 py-2">{{ cfg.environment }}</td>
                            <td class="px-3 py-2">
                                <span v-if="cfg.is_active" class="text-emerald-600 dark:text-emerald-400 font-medium">active</span>
                                <span v-else class="text-slate-400">inactive</span>
                            </td>
                            <td class="px-3 py-2 text-slate-500">{{ cfg.last_successful_at ?? '—' }}</td>
                            <td class="px-3 py-2 text-right space-x-2">
                                <button @click="toggleActive(cfg)" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ cfg.is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button @click="runHealthCheck(cfg)" class="text-xs text-slate-600 dark:text-slate-300 hover:underline">
                                    Health check
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
