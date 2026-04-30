<!-- SuperAdmin/Index.vue -->
<!-- Nostos Super Admin panel with three tabs: Tenants (all tenant orgs with user/participant
     counts), Health (system queue stats and table row counts), and Onboard (3-step wizard to
     provision a new PACE organization with tenant, first site, and admin user). -->

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    BuildingOffice2Icon,
    ServerIcon,
    PlusCircleIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline'

interface Summary {
    tenant_count: number
    user_count: number
    participant_count: number
}

interface TenantRow {
    id: number
    name: string
    slug: string
    transport_mode: 'direct' | 'broker'
    site_count: number
    user_count: number
    participant_count: number
    created_at: string
}

interface HealthRow {
    label: string
    count: number
}

interface TenantDetailStats {
    site_count: number
    user_count: number
    user_active_count: number
    participant_count: number
    enrolled_count: number
    last_login_at: string | null
    recent_notes_30d: number
    recent_appts_30d: number
    recent_transports_30d: number
    in_flight_transports: number
}
interface TenantDetail {
    id: number
    name: string
    slug: string
    transport_mode: 'direct' | 'broker'
    cms_contract_id: string | null
    state: string | null
    timezone: string | null
    auto_logout_minutes: number
    is_active: boolean
    created_at: string | null
}

interface HealthData {
    queue_stats: Record<string, number>
    table_counts: HealthRow[]
}

interface OnboardForm {
    tenant_name: string
    transport_mode: string
    auto_logout_minutes: number
    site_name: string
    site_city: string
    site_state: string
    admin_first_name: string
    admin_last_name: string
    admin_email: string
    admin_department: string
}

interface PageProps {
    summary: Summary
    [key: string]: unknown
}

const page = usePage<PageProps>()
const summary = page.props.summary

type Tab = 'tenants' | 'health' | 'onboard'
const activeTab = ref<Tab>('tenants')

// Tenants tab
const tenants = ref<TenantRow[]>([])
const tenantsLoading = ref(false)
const tenantsLoaded = ref(false)

// Tenant detail modal state
const tenantDetail = ref<TenantDetail | null>(null)
const tenantStats = ref<TenantDetailStats | null>(null)
const tenantDetailLoading = ref(false)
const tenantDetailError = ref<string | null>(null)
const tenantSaving = ref(false)
const tenantSaveError = ref<string | null>(null)
const tenantSaveSuccess = ref<string | null>(null)
const tenantEditDraft = ref<Partial<TenantDetail>>({})

async function openTenantDetail(t: TenantRow) {
    tenantDetail.value = null
    tenantStats.value = null
    tenantDetailError.value = null
    tenantSaveError.value = null
    tenantSaveSuccess.value = null
    tenantDetailLoading.value = true
    try {
        const { data } = await axios.get(`/super-admin-panel/tenants/${t.id}`)
        tenantDetail.value = data.tenant
        tenantStats.value = data.stats
        tenantEditDraft.value = { ...data.tenant }
    } catch (e: any) {
        tenantDetailError.value = e?.response?.data?.message ?? 'Failed to load tenant detail.'
    } finally {
        tenantDetailLoading.value = false
    }
}

function closeTenantDetail() {
    tenantDetail.value = null
    tenantStats.value = null
    tenantEditDraft.value = {}
}

async function saveTenantEdit() {
    if (!tenantDetail.value) return
    tenantSaving.value = true
    tenantSaveError.value = null
    tenantSaveSuccess.value = null

    // Confirm if transport mode is being switched and there are in-flight rides
    const switchingTransport = tenantEditDraft.value.transport_mode !== tenantDetail.value.transport_mode
    const inFlight = tenantStats.value?.in_flight_transports ?? 0
    if (switchingTransport && inFlight > 0) {
        const ok = confirm(
            `Switching transport mode from "${tenantDetail.value.transport_mode}" to "${tenantEditDraft.value.transport_mode}" while ${inFlight} transport request(s) are in-flight. ` +
            `Existing requests will keep their current state but new ones will use the new workflow. Continue?`
        )
        if (!ok) {
            tenantSaving.value = false
            return
        }
    }

    try {
        const { data } = await axios.patch(`/super-admin-panel/tenants/${tenantDetail.value.id}`, tenantEditDraft.value)
        tenantDetail.value = { ...tenantDetail.value, ...data.tenant }
        // Sync the row in the parent list too
        const idx = tenants.value.findIndex(x => x.id === data.tenant.id)
        if (idx >= 0) {
            tenants.value[idx] = {
                ...tenants.value[idx],
                name: data.tenant.name,
                transport_mode: data.tenant.transport_mode,
                is_active: data.tenant.is_active,
            } as TenantRow
        }
        tenantSaveSuccess.value = 'Saved.'
    } catch (e: any) {
        tenantSaveError.value = e?.response?.data?.message
            ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ')
            ?? 'Save failed.'
    } finally {
        tenantSaving.value = false
    }
}

// Health tab
const health = ref<HealthData | null>(null)
const healthLoading = ref(false)
const healthLoaded = ref(false)

// Onboard tab
const onboardStep = ref(1)
const onboardSubmitting = ref(false)
const onboardError = ref('')
const onboardSuccess = ref('')
const onboardForm = ref<OnboardForm>({
    tenant_name: '',
    transport_mode: 'direct',
    auto_logout_minutes: 15,
    site_name: '',
    site_city: '',
    site_state: '',
    admin_first_name: '',
    admin_last_name: '',
    admin_email: '',
    admin_department: 'it_admin',
})

const loadTenants = async () => {
    if (tenantsLoaded.value) return
    tenantsLoading.value = true
    try {
        const res = await axios.get('/super-admin-panel/tenants')
        tenants.value = res.data.tenants ?? []
        tenantsLoaded.value = true
    } catch {
        // silently handle
    } finally {
        tenantsLoading.value = false
    }
}

const loadHealth = async () => {
    if (healthLoaded.value) return
    healthLoading.value = true
    try {
        const res = await axios.get('/super-admin-panel/health')
        health.value = res.data
        healthLoaded.value = true
    } catch {
        // silently handle
    } finally {
        healthLoading.value = false
    }
}

const switchTab = (tab: Tab) => {
    activeTab.value = tab
    if (tab === 'tenants') loadTenants()
    if (tab === 'health') loadHealth()
}

const onboardNextStep = () => {
    if (onboardStep.value < 3) onboardStep.value++
}

const onboardPrevStep = () => {
    if (onboardStep.value > 1) onboardStep.value--
}

const submitOnboard = async () => {
    onboardSubmitting.value = true
    onboardError.value = ''
    onboardSuccess.value = ''
    try {
        await axios.post('/super-admin-panel/onboard', onboardForm.value)
        onboardSuccess.value = `Tenant "${onboardForm.value.tenant_name}" provisioned successfully. Welcome email sent to ${onboardForm.value.admin_email}.`
        onboardStep.value = 1
        onboardForm.value = {
            tenant_name: '', transport_mode: 'direct', auto_logout_minutes: 15,
            site_name: '', site_city: '', site_state: '',
            admin_first_name: '', admin_last_name: '', admin_email: '', admin_department: 'it_admin',
        }
        tenantsLoaded.value = false
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        onboardError.value = e.response?.data?.message || 'Failed to provision tenant.'
    } finally {
        onboardSubmitting.value = false
    }
}

const DEPT_LABELS: Record<string, string> = {
    it_admin: 'IT Admin', primary_care: 'Primary Care', enrollment: 'Enrollment',
    finance: 'Finance', qa_compliance: 'QA Compliance',
}

const stepLabels = ['Tenant Details', 'First Site', 'Admin User']

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, { dateStyle: 'short' })

onMounted(() => loadTenants())
</script>

<template>
    <AppShell>
        <Head title="Nostos Super Admin" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <!-- Header with KPI chips -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <BuildingOffice2Icon class="w-7 h-7 text-purple-600 dark:text-purple-400" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Nostos Super Admin</h1>
                        <p class="text-sm text-gray-500 dark:text-slate-400">Cross-tenant administration panel</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="text-center px-3 py-1 rounded-lg bg-purple-100 dark:bg-purple-900/30">
                        <div class="text-lg font-bold text-purple-700 dark:text-purple-300">{{ summary.tenant_count }}</div>
                        <div class="text-xs text-purple-600 dark:text-purple-400">Tenants</div>
                    </div>
                    <div class="text-center px-3 py-1 rounded-lg bg-blue-100 dark:bg-blue-900/30">
                        <div class="text-lg font-bold text-blue-700 dark:text-blue-300">{{ summary.user_count }}</div>
                        <div class="text-xs text-blue-600 dark:text-blue-400">Users</div>
                    </div>
                    <div class="text-center px-3 py-1 rounded-lg bg-green-100 dark:bg-green-900/30">
                        <div class="text-lg font-bold text-green-700 dark:text-green-300">{{ summary.participant_count }}</div>
                        <div class="text-xs text-green-600 dark:text-green-400">Participants</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-slate-700 mb-6">
                <nav class="flex gap-6">
                    <button
                        v-for="tab in (['tenants', 'health', 'onboard'] as const)"
                        :key="tab"
                        @click="switchTab(tab)"
                        :class="activeTab === tab
                            ? 'border-b-2 border-purple-600 text-purple-600 dark:text-purple-400'
                            : 'text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'"
                        class="pb-3 flex items-center gap-2 text-sm font-medium capitalize transition-colors"
                    >
                        <BuildingOffice2Icon v-if="tab === 'tenants'" class="w-4 h-4" />
                        <ServerIcon v-else-if="tab === 'health'" class="w-4 h-4" />
                        <PlusCircleIcon v-else class="w-4 h-4" />
                        {{ tab === 'onboard' ? 'Onboard Tenant' : tab.charAt(0).toUpperCase() + tab.slice(1) }}
                    </button>
                </nav>
            </div>

            <!-- Tenants Tab -->
            <div v-if="activeTab === 'tenants'">
                <div v-if="tenantsLoading" class="py-16 text-center text-gray-500 dark:text-slate-400 text-sm">Loading tenants...</div>
                <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                    <p class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400 border-b border-gray-200 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/30">
                        Click any tenant row to inspect stats and adjust configuration (transport mode, auto-logout, active status, etc.).
                    </p>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Organization</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Transport</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Sites</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Users</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Participants</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Status</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-for="t in tenants" :key="t.id"
                                @click="openTenantDetail(t)"
                                class="hover:bg-indigo-50 dark:hover:bg-slate-700/60 cursor-pointer transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ t.name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400 font-mono">{{ t.slug }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="[
                                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                        t.transport_mode === 'broker'
                                            ? 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300'
                                            : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'
                                    ]">{{ t.transport_mode }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ t.site_count }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ t.user_count }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ t.participant_count }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="t.is_active !== false" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Active</span>
                                    <span v-else class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">Inactive</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ formatDate(t.created_at) }}</td>
                            </tr>
                            <tr v-if="tenants.length === 0">
                                <td colspan="7" class="py-12 text-center text-gray-500 dark:text-slate-400">No tenants found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tenant detail / edit modal -->
                <div v-if="tenantDetail || tenantDetailLoading" role="dialog" aria-modal="true" aria-label="Tenant configuration"
                    @keydown.escape.window="closeTenantDetail"
                    class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 overflow-y-auto"
                    @click.self="closeTenantDetail">
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-3xl w-full p-6 my-8 max-h-[90vh] overflow-y-auto">
                        <div v-if="tenantDetailLoading" class="text-center py-12 text-gray-500 dark:text-slate-400">Loading tenant...</div>
                        <div v-else-if="tenantDetailError" class="text-rose-600 dark:text-rose-400 text-sm">{{ tenantDetailError }}</div>
                        <div v-else-if="tenantDetail">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ tenantDetail.name }}</h2>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">{{ tenantDetail.slug }}</p>
                                </div>
                                <button @click="closeTenantDetail" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200" aria-label="Close dialog">
                                    <XMarkIcon class="w-5 h-5" aria-hidden="true" />
                                </button>
                            </div>

                            <!-- Stats grid -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-6">
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Sites</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.site_count }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Users (active)</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.user_active_count }} / {{ tenantStats?.user_count }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Participants (enrolled)</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.enrolled_count }} / {{ tenantStats?.participant_count }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Last login</div>
                                    <div class="text-xs font-bold text-gray-900 dark:text-slate-100">{{ tenantStats?.last_login_at ? formatDate(tenantStats.last_login_at) : 'never' }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Notes (30d)</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.recent_notes_30d }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Appts (30d)</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.recent_appts_30d }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">Transports (30d)</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-slate-100 tabular-nums">{{ tenantStats?.recent_transports_30d }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 dark:border-slate-700 p-3"
                                    :class="(tenantStats?.in_flight_transports ?? 0) > 0 ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-gray-50 dark:bg-slate-800'">
                                    <div class="text-xs text-gray-500 dark:text-slate-400">In-flight transports</div>
                                    <div class="text-lg font-bold tabular-nums" :class="(tenantStats?.in_flight_transports ?? 0) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-slate-100'">{{ tenantStats?.in_flight_transports }}</div>
                                </div>
                            </div>

                            <!-- Edit form -->
                            <h3 class="text-sm font-bold text-gray-900 dark:text-slate-100 mb-2 border-t border-gray-200 dark:border-slate-700 pt-4">Configuration</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Display name</span>
                                    <input v-model="tenantEditDraft.name" type="text" maxlength="200"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">CMS contract ID</span>
                                    <input v-model="tenantEditDraft.cms_contract_id" type="text" maxlength="50"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-mono" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">State (2-letter)</span>
                                    <input v-model="tenantEditDraft.state" type="text" maxlength="2"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm uppercase" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Timezone</span>
                                    <input v-model="tenantEditDraft.timezone" type="text" placeholder="America/Los_Angeles"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Transport mode</span>
                                    <select v-model="tenantEditDraft.transport_mode"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                                        <option value="direct">direct (in-house fleet)</option>
                                        <option value="broker">broker (outside dispatcher)</option>
                                    </select>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                                        Switch routes new transport requests through a different workflow. In-flight rides keep their existing path.
                                    </p>
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Auto-logout (minutes)</span>
                                    <input v-model.number="tenantEditDraft.auto_logout_minutes" type="number" min="5" max="480"
                                        class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">HIPAA inactivity timeout. 5 - 480 minutes.</p>
                                </label>
                                <label class="flex items-center gap-2 col-span-1 md:col-span-2 mt-2">
                                    <input v-model="tenantEditDraft.is_active" type="checkbox" class="rounded text-indigo-600" />
                                    <span class="text-sm text-gray-700 dark:text-slate-300">Tenant active (uncheck = kill-switch ; staff lose access)</span>
                                </label>
                            </div>

                            <p v-if="tenantSaveError" class="text-xs text-rose-600 dark:text-rose-400 mt-3">{{ tenantSaveError }}</p>
                            <p v-if="tenantSaveSuccess" class="text-xs text-emerald-600 dark:text-emerald-400 mt-3">{{ tenantSaveSuccess }}</p>

                            <div class="flex justify-end gap-2 mt-5 pt-4 border-t border-gray-200 dark:border-slate-700">
                                <button @click="closeTenantDetail" class="px-4 py-2 rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">Close</button>
                                <button @click="saveTenantEdit" :disabled="tenantSaving"
                                    class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50">
                                    {{ tenantSaving ? 'Saving...' : 'Save changes' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Health Tab -->
            <div v-if="activeTab === 'health'">
                <div v-if="healthLoading" class="py-16 text-center text-gray-500 dark:text-slate-400 text-sm">Loading health data...</div>
                <div v-else-if="health" class="space-y-6">
                    <!-- Queue stats -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Horizon Queue Stats</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                            <div v-for="(count, queue) in health.queue_stats" :key="queue"
                                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 text-center shadow-sm">
                                <div class="text-xl font-bold" :class="count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-slate-100'">{{ count }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400 mt-1 font-mono">{{ queue }}</div>
                            </div>
                        </div>
                    </div>
                    <!-- Table counts -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Database Table Counts</h3>
                        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-slate-700/50">
                                    <tr>
                                        <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Table</th>
                                        <th class="text-right px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Count</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                                    <tr v-for="row in health.table_counts" :key="row.label" class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                                        <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-slate-300">{{ row.label }}</td>
                                        <td class="px-4 py-2 text-right text-gray-600 dark:text-slate-400">{{ row.count.toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Onboard Tab -->
            <div v-if="activeTab === 'onboard'">
                <!-- Success message -->
                <div v-if="onboardSuccess"
                    class="mb-6 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 flex items-center gap-2">
                    <CheckCircleIcon class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0" />
                    <p class="text-sm text-green-800 dark:text-green-300">{{ onboardSuccess }}</p>
                </div>

                <!-- Step indicator -->
                <div class="flex items-center gap-2 mb-6">
                    <template v-for="(label, idx) in stepLabels" :key="idx">
                        <div class="flex items-center gap-1">
                            <div :class="onboardStep === idx + 1
                                    ? 'bg-purple-600 text-white'
                                    : onboardStep > idx + 1
                                        ? 'bg-green-600 text-white'
                                        : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'"
                                class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold">
                                {{ onboardStep > idx + 1 ? '✓' : idx + 1 }}
                            </div>
                            <span class="text-sm" :class="onboardStep === idx + 1 ? 'font-medium text-gray-900 dark:text-slate-100' : 'text-gray-500 dark:text-slate-400'">
                                {{ label }}
                            </span>
                        </div>
                        <div v-if="idx < stepLabels.length - 1" class="flex-1 h-px bg-gray-200 dark:bg-slate-700"></div>
                    </template>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-6">
                    <!-- Step 1: Tenant Details -->
                    <div v-if="onboardStep === 1" class="space-y-4">
                        <h3 class="font-semibold text-gray-900 dark:text-slate-100 mb-2">Step 1: Tenant Details</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-tenant">Organization Name</label>
                            <input id="ob-tenant" v-model="onboardForm.tenant_name" required type="text" placeholder="Sunrise PACE"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-transport">Transport Mode</label>
                                <select id="ob-transport" v-model="onboardForm.transport_mode"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                    <option value="direct">Direct</option>
                                    <option value="broker">Broker</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-timeout">Auto-Logout (minutes)</label>
                                <input id="ob-timeout" v-model.number="onboardForm.auto_logout_minutes" type="number" min="5" max="60"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button @click="onboardNextStep" :disabled="!onboardForm.tenant_name"
                                class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
                                Next: Site Details
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: First Site -->
                    <div v-if="onboardStep === 2" class="space-y-4">
                        <h3 class="font-semibold text-gray-900 dark:text-slate-100 mb-2">Step 2: First PACE Site</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-site">Site Name</label>
                            <input id="ob-site" v-model="onboardForm.site_name" required type="text" placeholder="Sunrise PACE East"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-city">City</label>
                                <input id="ob-city" v-model="onboardForm.site_city" type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-state">State</label>
                                <input id="ob-state" v-model="onboardForm.site_state" type="text" maxlength="2" placeholder="OH"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm uppercase" />
                            </div>
                        </div>
                        <div class="flex justify-between">
                            <button @click="onboardPrevStep"
                                class="px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm transition-colors">
                                Back
                            </button>
                            <button @click="onboardNextStep" :disabled="!onboardForm.site_name"
                                class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
                                Next: Admin User
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Admin User -->
                    <div v-if="onboardStep === 3" class="space-y-4">
                        <h3 class="font-semibold text-gray-900 dark:text-slate-100 mb-2">Step 3: First Admin User</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-fname">First Name</label>
                                <input id="ob-fname" v-model="onboardForm.admin_first_name" required type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-lname">Last Name</label>
                                <input id="ob-lname" v-model="onboardForm.admin_last_name" required type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-email">Email Address</label>
                            <input id="ob-email" v-model="onboardForm.admin_email" required type="email"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="ob-dept">Department</label>
                            <select id="ob-dept" v-model="onboardForm.admin_department"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option v-for="(label, key) in DEPT_LABELS" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <p v-if="onboardError" class="text-sm text-red-600 dark:text-red-400 flex items-center gap-1">
                            <ExclamationTriangleIcon class="w-4 h-4" />
                            {{ onboardError }}
                        </p>
                        <div class="flex justify-between">
                            <button @click="onboardPrevStep"
                                class="px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm transition-colors">
                                Back
                            </button>
                            <button @click="submitOnboard"
                                :disabled="onboardSubmitting || !onboardForm.admin_email"
                                class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
                                {{ onboardSubmitting ? 'Provisioning...' : 'Provision Tenant' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
