<!-- ItAdmin/Users.vue -->
<!-- IT Admin user management page. Lists all provisioned users for the tenant
     with search + department + status filters. Clicking a row opens the
     UserDetailsModal — credentials summary, designations management, activity
     feed (data-mutating actions only), and admin actions (deactivate / reset
     access) all in one place. -->

<script setup lang="ts">
// ─── ItAdmin/Users ──────────────────────────────────────────────────────────
// User provisioning + role/department/designation assignment for tenant
// staff. Search, department filter, active-status toggle. Row click opens a
// detail modal; the table itself is purely informational (no inline actions).
//
// Audience: IT Admin only.
//
// Notable rules:
//   - Department + designation drive feature gating across the EMR; pay
//     attention to the `shared_users_department_check` enum (canonical
//     departments). Nurses currently sit under primary_care/home_care —
//     no `nursing` enum value yet.
//   - Active toggle is reversible; deletion is intentionally not exposed
//     (audit trail integrity — flip to inactive instead).
//   - The detail modal's activity feed filters out pure-read actions
//     (page views, navigation, searches). Filter list lives server-side
//     in UserProvisioningController::ACTIVITY_FEED_EXCLUDE_PATTERNS.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ShieldCheckIcon,
    PlusIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
    ClockIcon,
    KeyIcon,
    DocumentCheckIcon,
    ExclamationTriangleIcon,
    BoltIcon,
} from '@heroicons/vue/24/outline'

interface UserRow {
    id: number
    first_name: string
    last_name: string
    email: string
    department: string
    is_active: boolean
    created_at: string
    designations: string[]
}

interface UserDetail {
    user: {
        id: number
        first_name: string
        last_name: string
        email: string
        department: string
        role: string
        is_active: boolean
        designations: string[]
        site: { id: number; name: string } | null
        last_login_at: string | null
        failed_login_attempts: number
        locked_until: string | null
        provisioned_at: string | null
        provisioned_by: string | null
        created_at: string | null
    }
    credentials: {
        active_count: number
        expiring_count: number
        expired_count: number
        total_count: number
    }
    training: {
        total_hours_12mo: number
        by_category: Record<string, number>
    }
    activity: {
        count_30_days: number
        count_90_days: number
        top_actions: { action: string; count: number }[]
        recent: {
            id: number
            action: string
            resource_type: string | null
            resource_id: number | null
            description: string | null
            created_at: string | null
        }[]
    }
}

interface ProvisionForm {
    first_name: string
    last_name: string
    email: string
    department: string
}

interface DesignationDetail {
    label: string
    summary: string
    permissions: string[]
    notifications: string[]
    reserved: string[]
}

interface Props {
    users: UserRow[]
    designationLabels: Record<string, string>
    designationDetails: Record<string, DesignationDetail>
    deptLabels: Record<string, string>
}

const props = defineProps<Props>()

// ── Table state ─────────────────────────────────────────────────────────────
const users = ref<UserRow[]>(props.users)
const search = ref('')
const filterDept = ref('all')
const filterStatus = ref<'all' | 'active' | 'inactive'>('all')

// ── Detail-modal state ──────────────────────────────────────────────────────
const detailUserId = ref<number | null>(null)
const detail = ref<UserDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const togglingActive = ref(false)
const resettingAccess = ref(false)
const savingDesignations = ref(false)

// ── Provision-modal state (unchanged) ───────────────────────────────────────
const showProvision = ref(false)
const provisioning = ref(false)
const provisionError = ref('')
const provisionForm = ref<ProvisionForm>({
    first_name: '', last_name: '', email: '', department: '',
})

const DEPT_LABELS: Record<string, string> = props.deptLabels || {
    primary_care: 'Primary Care', therapies: 'Therapies', social_work: 'Social Work',
    behavioral_health: 'Behavioral Health', dietary: 'Dietary', activities: 'Activities',
    home_care: 'Home Care', transportation: 'Transportation', pharmacy: 'Pharmacy',
    idt: 'IDT', enrollment: 'Enrollment', finance: 'Finance', qa_compliance: 'QA Compliance',
    it_admin: 'IT Admin',
}

const TRAINING_CATEGORY_LABELS: Record<string, string> = {
    hipaa: 'HIPAA', infection_control: 'Infection Control', dementia: 'Dementia Care',
    elder_abuse: 'Elder Abuse', cultural_competency: 'Cultural Competency',
    cpr_first_aid: 'CPR / First Aid', other: 'Other',
}

const filtered = computed(() => {
    return users.value.filter(u => {
        const name = `${u.first_name} ${u.last_name}`.toLowerCase()
        const matchSearch = !search.value
            || name.includes(search.value.toLowerCase())
            || u.email.toLowerCase().includes(search.value.toLowerCase())
        const matchDept = filterDept.value === 'all' || u.department === filterDept.value
        const matchStatus = filterStatus.value === 'all'
            || (filterStatus.value === 'active' && u.is_active)
            || (filterStatus.value === 'inactive' && !u.is_active)
        return matchSearch && matchDept && matchStatus
    })
})

// ── Open / close detail modal ──────────────────────────────────────────────

async function openDetail(user: UserRow) {
    detailUserId.value = user.id
    detail.value = null
    detailLoading.value = true
    detailError.value = null
    try {
        const res = await axios.get<UserDetail>(`/it-admin/users/${user.id}/details`)
        detail.value = res.data
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        detailError.value = err.response?.data?.message
            ?? `Could not load user details (${err.response?.status ?? 'network error'}).`
    } finally {
        detailLoading.value = false
    }
}

function closeDetail() {
    detailUserId.value = null
    detail.value = null
    detailError.value = null
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        if (detailUserId.value !== null) closeDetail()
        else if (showProvision.value) { showProvision.value = false; resetProvisionForm() }
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

// ── Modal actions ──────────────────────────────────────────────────────────

async function toggleActive() {
    if (!detail.value) return
    const u = detail.value.user
    const action = u.is_active ? 'deactivate' : 'reactivate'
    togglingActive.value = true
    detailError.value = null
    try {
        await axios.post(`/it-admin/users/${u.id}/${action}`)
        // Update modal copy + the underlying table row
        detail.value.user.is_active = !u.is_active
        users.value = users.value.map(row => row.id === u.id ? { ...row, is_active: !u.is_active } : row)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        detailError.value = err.response?.data?.message
            ?? `Could not ${action} user (${err.response?.status ?? 'network error'}).`
    } finally {
        togglingActive.value = false
    }
}

async function resetAccess() {
    if (!detail.value) return
    const u = detail.value.user
    resettingAccess.value = true
    detailError.value = null
    try {
        await axios.post(`/it-admin/users/${u.id}/reset-access`)
        // Surface a brief success indicator inline; no field state to update.
        detailError.value = '✓ Sessions invalidated. The user will be logged out on their next request.'
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        detailError.value = err.response?.data?.message
            ?? `Could not reset access (${err.response?.status ?? 'network error'}).`
    } finally {
        resettingAccess.value = false
    }
}

async function toggleDesignation(key: string) {
    if (!detail.value) return
    const u = detail.value.user
    const prev = [...u.designations]
    const next = prev.includes(key) ? prev.filter(d => d !== key) : [...prev, key]
    detail.value.user.designations = next  // optimistic update
    savingDesignations.value = true
    detailError.value = null
    try {
        await axios.patch(`/it-admin/users/${u.id}/designations`, { designations: next })
        // Mirror onto the underlying table row so designation chips stay current
        users.value = users.value.map(row => row.id === u.id ? { ...row, designations: next } : row)
    } catch (e: unknown) {
        // Roll back optimistic update + surface error
        if (detail.value) detail.value.user.designations = prev
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        detailError.value = err.response?.data?.message
            ?? `Could not save designation (${err.response?.status ?? 'network error'}).`
    } finally {
        savingDesignations.value = false
    }
}

// ── Provision flow (unchanged) ──────────────────────────────────────────────

function resetProvisionForm() {
    provisionForm.value = { first_name: '', last_name: '', email: '', department: '' }
    provisionError.value = ''
}

async function submitProvision() {
    provisioning.value = true
    provisionError.value = ''
    try {
        const res = await axios.post('/it-admin/users', provisionForm.value)
        users.value = [res.data.user, ...users.value]
        showProvision.value = false
        resetProvisionForm()
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        provisionError.value = e.response?.data?.message || 'Failed to provision user.'
    } finally {
        provisioning.value = false
    }
}

// ── Formatting helpers ──────────────────────────────────────────────────────

const formatDate = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'short' }) : '—'

const formatDateTime = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : '—'

function relativeTime(iso: string | null | undefined): string {
    if (!iso) return '—'
    const t = new Date(iso).getTime()
    if (isNaN(t)) return '—'
    const diff = (Date.now() - t) / 1000  // seconds
    if (diff < 60) return 'just now'
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    if (diff < 86400 * 30) return `${Math.floor(diff / 86400)}d ago`
    if (diff < 86400 * 365) return `${Math.floor(diff / 86400 / 30)}mo ago`
    return `${Math.floor(diff / 86400 / 365)}y ago`
}

// Humanize an action key like "participant.allergy.created" → "Allergy created"
function humanizeAction(action: string): string {
    const last = action.split('.').slice(-2).join(' · ').replace(/_/g, ' ')
    return last.charAt(0).toUpperCase() + last.slice(1)
}
</script>

<template>
    <AppShell>
        <Head title="IT Admin: Users" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">User Management</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        Click any user row to view credentials, activity, and admin actions.
                    </p>
                </div>
                <button
                    @click="showProvision = true"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors"
                    aria-label="Provision new user"
                >
                    <PlusIcon class="w-4 h-4" />
                    Provision User
                </button>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-5">
                <div class="relative flex-1 min-w-48">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by name or email..."
                        class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        aria-label="Search users"
                    />
                </div>
                <select name="filterDept" v-model="filterDept"
                    class="px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by department"
                >
                    <option value="all">All Departments</option>
                    <option v-for="(label, key) in DEPT_LABELS" :key="key" :value="key">{{ label }}</option>
                </select>
                <select name="filterStatus" v-model="filterStatus"
                    class="px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by status"
                >
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- Table — Actions column removed; whole row is clickable -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Name</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Department</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Joined</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="user in filtered"
                            :key="user.id"
                            class="cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
                            @click="openDetail(user)"
                            tabindex="0"
                            @keydown.enter="openDetail(user)"
                            :aria-label="`Open details for ${user.first_name} ${user.last_name}`"
                        >
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-slate-100">
                                    {{ user.first_name }} {{ user.last_name }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-slate-400">{{ user.email }}</div>
                                <div v-if="user.designations.length > 0" class="flex flex-wrap gap-1 mt-1">
                                    <span
                                        v-for="d in user.designations"
                                        :key="d"
                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300"
                                    >
                                        <ShieldCheckIcon class="w-3 h-3" />
                                        {{ props.designationLabels?.[d] ?? d }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 capitalize">
                                {{ DEPT_LABELS[user.department] ?? user.department.replace('_', ' ') }}
                            </td>
                            <td class="px-4 py-3">
                                <span :class="user.is_active
                                    ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
                                    : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'"
                                    class="inline-block px-2 py-0.5 rounded-full text-xs font-medium"
                                >
                                    {{ user.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ formatDate(user.created_at) }}</td>
                        </tr>
                        <tr v-if="filtered.length === 0">
                            <td colspan="4" class="text-center py-12 text-gray-500 dark:text-slate-400">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ─── Detail modal ────────────────────────────────────────────── -->
        <div
            v-if="detailUserId !== null"
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 overflow-y-auto py-8"
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-detail-heading"
            @click.self="closeDetail"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-3xl mx-4 my-4">

                <!-- Loading -->
                <div v-if="detailLoading" class="p-12 text-center text-gray-500 dark:text-slate-400">
                    Loading user details…
                </div>

                <!-- Error fetching -->
                <div v-else-if="!detail" class="p-8">
                    <div class="flex items-start justify-between mb-4">
                        <h2 id="user-detail-heading" class="text-lg font-bold text-gray-900 dark:text-slate-100">User detail</h2>
                        <button @click="closeDetail" aria-label="Close" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200">
                            <XMarkIcon class="w-5 h-5" />
                        </button>
                    </div>
                    <p v-if="detailError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ detailError }}</p>
                </div>

                <!-- Loaded -->
                <div v-else class="divide-y divide-gray-100 dark:divide-slate-700">

                    <!-- Header -->
                    <div class="p-6 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 id="user-detail-heading" class="text-xl font-bold text-gray-900 dark:text-slate-100 truncate">
                                {{ detail.user.first_name }} {{ detail.user.last_name }}
                            </h2>
                            <div class="text-sm text-gray-500 dark:text-slate-400 mt-1 truncate">{{ detail.user.email }}</div>
                            <div class="flex items-center flex-wrap gap-2 mt-2">
                                <span class="text-xs px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 capitalize">
                                    {{ DEPT_LABELS[detail.user.department] ?? detail.user.department.replace('_', ' ') }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 capitalize">
                                    {{ detail.user.role }}
                                </span>
                                <span :class="detail.user.is_active
                                    ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
                                    : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'"
                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                >
                                    {{ detail.user.is_active ? 'Active' : 'Inactive' }}
                                </span>
                                <span v-if="detail.user.locked_until && new Date(detail.user.locked_until) > new Date()"
                                    class="text-xs px-2 py-0.5 rounded-full font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
                                    :title="`Locked until ${formatDateTime(detail.user.locked_until)}`"
                                >
                                    Locked
                                </span>
                            </div>
                        </div>
                        <button @click="closeDetail" aria-label="Close" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200 shrink-0">
                            <XMarkIcon class="w-5 h-5" />
                        </button>
                    </div>

                    <!-- Inline error / status banner -->
                    <div v-if="detailError" class="px-6 py-3 bg-red-50 dark:bg-red-950/40 text-sm text-red-700 dark:text-red-300" role="alert" data-testid="user-detail-error">
                        {{ detailError }}
                    </div>

                    <!-- Account section -->
                    <div class="p-6">
                        <h3 class="text-xs uppercase tracking-wide font-semibold text-gray-500 dark:text-slate-400 mb-3 flex items-center gap-1.5">
                            <KeyIcon class="w-3.5 h-3.5" /> Account
                        </h3>
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div><dt class="text-gray-500 dark:text-slate-400">Last login</dt><dd class="text-gray-900 dark:text-slate-100">{{ formatDateTime(detail.user.last_login_at) }}<span v-if="detail.user.last_login_at" class="text-xs text-gray-500 dark:text-slate-400 ml-1">({{ relativeTime(detail.user.last_login_at) }})</span></dd></div>
                            <div><dt class="text-gray-500 dark:text-slate-400">Joined</dt><dd class="text-gray-900 dark:text-slate-100">{{ formatDate(detail.user.created_at) }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-slate-400">Provisioned by</dt><dd class="text-gray-900 dark:text-slate-100">{{ detail.user.provisioned_by ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-slate-400">Site</dt><dd class="text-gray-900 dark:text-slate-100">{{ detail.user.site?.name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-slate-400">Failed login attempts</dt><dd :class="detail.user.failed_login_attempts > 0 ? 'text-amber-700 dark:text-amber-300 font-medium' : 'text-gray-900 dark:text-slate-100'">{{ detail.user.failed_login_attempts }}</dd></div>
                            <div v-if="detail.user.locked_until && new Date(detail.user.locked_until) > new Date()">
                                <dt class="text-gray-500 dark:text-slate-400">Locked until</dt>
                                <dd class="text-amber-700 dark:text-amber-300 font-medium">{{ formatDateTime(detail.user.locked_until) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Credentials & training -->
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs uppercase tracking-wide font-semibold text-gray-500 dark:text-slate-400 flex items-center gap-1.5">
                                <DocumentCheckIcon class="w-3.5 h-3.5" /> Credentials &amp; Training
                            </h3>
                            <a
                                :href="`/it-admin/users/${detail.user.id}/credentials`"
                                class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                Manage credentials →
                            </a>
                        </div>
                        <div class="grid grid-cols-3 gap-3 mb-3">
                            <div class="rounded-lg border border-green-200 dark:border-green-900/50 bg-green-50 dark:bg-green-950/30 px-3 py-2">
                                <div class="text-xs text-green-700 dark:text-green-400">Active</div>
                                <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ detail.credentials.active_count }}</div>
                            </div>
                            <div class="rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/30 px-3 py-2">
                                <div class="text-xs text-amber-700 dark:text-amber-400">Expiring (≤60d)</div>
                                <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ detail.credentials.expiring_count }}</div>
                            </div>
                            <div class="rounded-lg border border-rose-200 dark:border-rose-900/50 bg-rose-50 dark:bg-rose-950/30 px-3 py-2">
                                <div class="text-xs text-rose-700 dark:text-rose-400">Expired</div>
                                <div class="text-2xl font-bold text-rose-700 dark:text-rose-300">{{ detail.credentials.expired_count }}</div>
                            </div>
                        </div>
                        <div class="text-sm text-gray-700 dark:text-slate-300">
                            <span class="font-medium">{{ detail.training.total_hours_12mo }}h</span> training in last 12 months
                            <span v-if="Object.keys(detail.training.by_category).length > 0" class="text-gray-500 dark:text-slate-400">
                                ·
                                <span v-for="(hrs, cat, idx) in detail.training.by_category" :key="cat">
                                    {{ idx > 0 ? ', ' : '' }}{{ TRAINING_CATEGORY_LABELS[cat] ?? cat }}: {{ hrs }}h
                                </span>
                            </span>
                        </div>
                    </div>

                    <!-- Designations — vertical list with detail per designation -->
                    <div class="p-6">
                        <h3 class="text-xs uppercase tracking-wide font-semibold text-gray-500 dark:text-slate-400 mb-3 flex items-center gap-1.5">
                            <ShieldCheckIcon class="w-3.5 h-3.5" />
                            Accountability Designations
                            <span v-if="savingDesignations" class="ml-1 text-indigo-400 dark:text-indigo-300 normal-case">(saving…)</span>
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">
                            Designations control who is notified for specific events and who can approve specific workflows
                            (they do <em>not</em> affect general department access). A user may hold multiple. Toggle any
                            checkbox to add or remove the designation — saves immediately.
                        </p>

                        <ul class="space-y-3">
                            <li
                                v-for="(label, key) in props.designationLabels"
                                :key="key"
                                class="rounded-lg border border-gray-200 dark:border-slate-700 p-3 transition-colors"
                                :class="detail.user.designations.includes(key)
                                    ? 'bg-indigo-50/40 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-900/50'
                                    : 'bg-white dark:bg-slate-800/30'"
                            >
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        :checked="detail.user.designations.includes(key)"
                                        @change="toggleDesignation(key)"
                                        class="mt-0.5 rounded border-gray-300 dark:border-slate-600 shrink-0"
                                        :aria-label="`Toggle ${label} designation`"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ label }}</div>
                                        <div
                                            v-if="props.designationDetails?.[key]?.summary"
                                            class="text-xs text-gray-600 dark:text-slate-400 mt-0.5"
                                        >
                                            {{ props.designationDetails[key].summary }}
                                        </div>
                                    </div>
                                </label>

                                <!-- Detail bullets — only render when we have content for this designation -->
                                <div
                                    v-if="props.designationDetails?.[key]"
                                    class="mt-2 ml-6 space-y-2 text-xs"
                                >
                                    <!-- Permissions / approval gates -->
                                    <div v-if="props.designationDetails[key].permissions.length > 0">
                                        <div class="font-semibold text-emerald-700 dark:text-emerald-400 mb-1">
                                            Permissions
                                        </div>
                                        <ul class="list-disc list-outside ml-4 space-y-0.5 text-gray-700 dark:text-slate-300">
                                            <li v-for="(p, i) in props.designationDetails[key].permissions" :key="`p-${i}`">{{ p }}</li>
                                        </ul>
                                    </div>

                                    <!-- Notifications -->
                                    <div v-if="props.designationDetails[key].notifications.length > 0">
                                        <div class="font-semibold text-blue-700 dark:text-blue-400 mb-1">
                                            Notifications
                                        </div>
                                        <ul class="list-disc list-outside ml-4 space-y-0.5 text-gray-700 dark:text-slate-300">
                                            <li v-for="(n, i) in props.designationDetails[key].notifications" :key="`n-${i}`">{{ n }}</li>
                                        </ul>
                                    </div>

                                    <!-- Reserved (planned but not yet wired) -->
                                    <div v-if="props.designationDetails[key].reserved.length > 0">
                                        <div class="font-semibold text-amber-700 dark:text-amber-400 mb-1 flex items-center gap-1">
                                            Reserved
                                            <span class="font-normal text-amber-700/70 dark:text-amber-500/80 italic normal-case">— intent, not yet wired</span>
                                        </div>
                                        <ul class="list-disc list-outside ml-4 space-y-0.5 text-gray-600 dark:text-slate-400">
                                            <li v-for="(r, i) in props.designationDetails[key].reserved" :key="`r-${i}`">{{ r }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Activity stats + audit log -->
                    <div class="p-6">
                        <h3 class="text-xs uppercase tracking-wide font-semibold text-gray-500 dark:text-slate-400 mb-3 flex items-center gap-1.5">
                            <BoltIcon class="w-3.5 h-3.5" /> Activity
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">
                            Data-mutating actions only — page views, navigation, and searches are excluded.
                        </p>

                        <!-- Stats -->
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="rounded-lg border border-gray-200 dark:border-slate-700 px-3 py-2">
                                <div class="text-xs text-gray-500 dark:text-slate-400">Last 30 days</div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ detail.activity.count_30_days }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 dark:border-slate-700 px-3 py-2">
                                <div class="text-xs text-gray-500 dark:text-slate-400">Last 90 days</div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ detail.activity.count_90_days }}</div>
                            </div>
                        </div>

                        <!-- Top actions -->
                        <div v-if="detail.activity.top_actions.length > 0" class="mb-4">
                            <div class="text-xs font-semibold text-gray-500 dark:text-slate-400 mb-2">Top actions (last 90 days)</div>
                            <div class="space-y-1">
                                <div v-for="t in detail.activity.top_actions" :key="t.action" class="flex items-center justify-between text-sm">
                                    <span class="text-gray-700 dark:text-slate-300">{{ humanizeAction(t.action) }}</span>
                                    <span class="text-xs text-gray-500 dark:text-slate-400 font-mono">{{ t.count }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Recent feed -->
                        <div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-slate-400 mb-2">Recent activity (last 50)</div>
                            <div v-if="detail.activity.recent.length === 0" class="text-sm text-gray-500 dark:text-slate-400 italic">
                                No data-mutating actions on record yet.
                            </div>
                            <ol v-else class="max-h-64 overflow-y-auto border border-gray-200 dark:border-slate-700 rounded-lg divide-y divide-gray-100 dark:divide-slate-700">
                                <li v-for="row in detail.activity.recent" :key="row.id" class="px-3 py-2 text-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-900 dark:text-slate-100 truncate">{{ humanizeAction(row.action) }}</div>
                                            <div v-if="row.description" class="text-xs text-gray-600 dark:text-slate-400 mt-0.5 line-clamp-2">{{ row.description }}</div>
                                            <div v-if="row.resource_type" class="text-xs text-gray-500 dark:text-slate-500 mt-0.5">
                                                {{ row.resource_type }}<span v-if="row.resource_id"> #{{ row.resource_id }}</span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap shrink-0" :title="formatDateTime(row.created_at)">
                                            {{ relativeTime(row.created_at) }}
                                        </span>
                                    </div>
                                </li>
                            </ol>
                        </div>
                    </div>

                    <!-- Admin actions footer -->
                    <div class="p-6 bg-gray-50 dark:bg-slate-900/40 flex flex-wrap items-center justify-end gap-3">
                        <button
                            @click="resetAccess"
                            :disabled="resettingAccess"
                            class="text-sm px-3 py-2 rounded-lg border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30 disabled:opacity-50 transition-colors inline-flex items-center gap-1.5"
                            title="Invalidate all sessions; user is logged out on next request. Account remains active."
                        >
                            <ClockIcon class="w-4 h-4" />
                            {{ resettingAccess ? 'Resetting…' : 'Reset Access' }}
                        </button>
                        <button
                            @click="toggleActive"
                            :disabled="togglingActive"
                            :class="detail.user.is_active
                                ? 'border-rose-300 dark:border-rose-700 text-rose-700 dark:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30'
                                : 'border-green-300 dark:border-green-700 text-green-700 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-950/30'"
                            class="text-sm px-3 py-2 rounded-lg border disabled:opacity-50 transition-colors inline-flex items-center gap-1.5"
                            :title="detail.user.is_active ? 'Set is_active=false; logs them out and blocks new logins.' : 'Set is_active=true; allow OTP requests again.'"
                        >
                            <ExclamationTriangleIcon class="w-4 h-4" />
                            {{ togglingActive ? '…' : (detail.user.is_active ? 'Deactivate' : 'Reactivate') }}
                        </button>
                        <button
                            @click="closeDetail"
                            class="text-sm px-3 py-2 rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Provision modal (unchanged) ─────────────────────────────── -->
        <div v-if="showProvision" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">Provision New User</h2>
                <form @submit.prevent="submitProvision" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="prov-first">First Name</label>
                            <input id="prov-first" v-model="provisionForm.first_name" required type="text"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="prov-last">Last Name</label>
                            <input id="prov-last" v-model="provisionForm.last_name" required type="text"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="prov-email">Email Address</label>
                        <input id="prov-email" v-model="provisionForm.email" required type="email"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="prov-dept">Department</label>
                        <select id="prov-dept" v-model="provisionForm.department" required
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                            <option value="">Select department...</option>
                            <option v-for="(label, key) in DEPT_LABELS" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <p v-if="provisionError" class="text-sm text-red-600 dark:text-red-400">{{ provisionError }}</p>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showProvision = false; resetProvisionForm()"
                            class="px-4 py-2 text-sm rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" :disabled="provisioning"
                            class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 transition-colors">
                            {{ provisioning ? 'Provisioning...' : 'Provision' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppShell>
</template>
