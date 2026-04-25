<!-- ItAdmin/Users.vue -->
<!-- IT Admin user management page. Displays all provisioned users for the tenant with search,
     department filter, and active/inactive toggle. Admins can expand rows to manage user
     designations and use the provision modal to create new user accounts. -->

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ShieldCheckIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    PlusIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

interface UserDesignation {
    key: string
    label: string
}

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

interface ProvisionForm {
    first_name: string
    last_name: string
    email: string
    department: string
}

interface Props {
    users: UserRow[]
    designationLabels: Record<string, string>
    deptLabels: Record<string, string>
}

const props = defineProps<Props>()

const users = ref<UserRow[]>(props.users)
const search = ref('')
const filterDept = ref('all')
const filterStatus = ref<'all' | 'active' | 'inactive'>('all')
const expandedRow = ref<number | null>(null)
const togglingId = ref<number | null>(null)
const savingDesignations = ref<number | null>(null)
// Phase V1 — Audit-10 C1: per-user error surfacing instead of silent catch.
const rowError = ref<{ id: number; message: string } | null>(null)
function showRowError(id: number, message: string) {
    rowError.value = { id, message }
    setTimeout(() => {
        if (rowError.value?.id === id) rowError.value = null
    }, 6000)
}
const showProvision = ref(false)
const provisioning = ref(false)
const provisionError = ref('')

const provisionForm = ref<ProvisionForm>({
    first_name: '',
    last_name: '',
    email: '',
    department: '',
})

const DEPT_LABELS: Record<string, string> = props.deptLabels || {
    primary_care: 'Primary Care',
    therapies: 'Therapies',
    social_work: 'Social Work',
    behavioral_health: 'Behavioral Health',
    dietary: 'Dietary',
    activities: 'Activities',
    home_care: 'Home Care',
    transportation: 'Transportation',
    pharmacy: 'Pharmacy',
    idt: 'IDT',
    enrollment: 'Enrollment',
    finance: 'Finance',
    qa_compliance: 'QA Compliance',
    it_admin: 'IT Admin',
}

const filtered = computed(() => {
    return users.value.filter(u => {
        const name = `${u.first_name} ${u.last_name}`.toLowerCase()
        const matchSearch = !search.value || name.includes(search.value.toLowerCase()) || u.email.toLowerCase().includes(search.value.toLowerCase())
        const matchDept = filterDept.value === 'all' || u.department === filterDept.value
        const matchStatus = filterStatus.value === 'all'
            || (filterStatus.value === 'active' && u.is_active)
            || (filterStatus.value === 'inactive' && !u.is_active)
        return matchSearch && matchDept && matchStatus
    })
})

const toggleRow = (id: number) => {
    expandedRow.value = expandedRow.value === id ? null : id
}

const toggleActive = async (user: UserRow) => {
    togglingId.value = user.id
    rowError.value = null
    const action = user.is_active ? 'deactivate' : 'reactivate'
    try {
        await axios.post(`/it-admin/users/${user.id}/${action}`)
        users.value = users.value.map(u => u.id === user.id ? { ...u, is_active: !u.is_active } : u)
    } catch (e: any) {
        // Phase V1 — surface failure rather than silent (Audit-10 C1).
        const msg = e?.response?.data?.message
            ?? `Could not ${action} user (${e?.response?.status ?? 'network error'}).`
        showRowError(user.id, msg)
    } finally {
        togglingId.value = null
    }
}

const toggleDesignation = async (user: UserRow, key: string) => {
    const prev = [...user.designations]
    const next = prev.includes(key) ? prev.filter(d => d !== key) : [...prev, key]
    users.value = users.value.map(u => u.id === user.id ? { ...u, designations: next } : u)
    savingDesignations.value = user.id
    rowError.value = null
    try {
        await axios.patch(`/it-admin/users/${user.id}/designations`, { designations: next })
    } catch (e: any) {
        // Roll back optimistic update + surface error (Audit-10 C1 sibling fix).
        users.value = users.value.map(u => u.id === user.id ? { ...u, designations: prev } : u)
        const msg = e?.response?.data?.message
            ?? `Could not save designation (${e?.response?.status ?? 'network error'}).`
        showRowError(user.id, msg)
    } finally {
        savingDesignations.value = null
    }
}

const resetProvisionForm = () => {
    provisionForm.value = { first_name: '', last_name: '', email: '', department: '' }
    provisionError.value = ''
}

const submitProvision = async () => {
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

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, { dateStyle: 'short' })
</script>

<template>
    <AppShell>
        <Head title="IT Admin: Users" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">User Management</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">Manage provisioned staff accounts</p>
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
                <select name="filterDept"
                    v-model="filterDept"
                    class="px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by department"
                >
                    <option value="all">All Departments</option>
                    <option v-for="(label, key) in DEPT_LABELS" :key="key" :value="key">{{ label }}</option>
                </select>
                <select name="filterStatus"
                    v-model="filterStatus"
                    class="px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by status"
                >
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Name</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Department</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Joined</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <template v-for="user in filtered" :key="user.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">
                                        {{ user.first_name }} {{ user.last_name }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ user.email }}</div>
                                    <!-- Designation chips -->
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
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <!-- Phase 4 (MVP roadmap): Staff credentials link (§460.64-71) -->
                                        <a
                                            :href="`/it-admin/users/${user.id}/credentials`"
                                            class="text-xs px-2 py-1 rounded border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"
                                            title="Manage licenses, TB clearance, training hours"
                                        >
                                            Credentials
                                        </a>
                                        <button
                                            @click="toggleActive(user)"
                                            :disabled="togglingId === user.id"
                                            class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 disabled:opacity-50 transition-colors"
                                            :aria-label="user.is_active ? 'Deactivate user' : 'Reactivate user'"
                                        >
                                            {{ togglingId === user.id ? '...' : (user.is_active ? 'Deactivate' : 'Reactivate') }}
                                        </button>
                                        <span v-if="rowError && rowError.id === user.id"
                                              role="alert"
                                              class="text-xs text-red-600 dark:text-red-400 ml-2"
                                              data-testid="users-row-error">
                                            {{ rowError.message }}
                                        </span>
                                        <button
                                            @click="toggleRow(user.id)"
                                            class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300"
                                            :aria-label="expandedRow === user.id ? 'Collapse designations' : 'Expand designations'"
                                        >
                                            <ChevronUpIcon v-if="expandedRow === user.id" class="w-4 h-4" />
                                            <ChevronDownIcon v-else class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Designation panel -->
                            <tr v-if="expandedRow === user.id" :key="`${user.id}-designations`">
                                <td colspan="5" class="px-4 py-3 bg-indigo-50 dark:bg-indigo-950/20 border-t border-indigo-100 dark:border-indigo-900/30">
                                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-400 mb-2 flex items-center gap-1">
                                        <ShieldCheckIcon class="w-3.5 h-3.5" />
                                        Accountability Designations
                                        <span v-if="savingDesignations === user.id" class="ml-1 text-indigo-400">(saving...)</span>
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <label
                                            v-for="(label, key) in props.designationLabels"
                                            :key="key"
                                            class="flex items-center gap-1.5 cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="user.designations.includes(key)"
                                                @change="toggleDesignation(user, key)"
                                                class="rounded border-gray-300 dark:border-slate-600"
                                                :aria-label="label"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-slate-300">{{ label }}</span>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="filtered.length === 0">
                            <td colspan="5" class="text-center py-12 text-gray-500 dark:text-slate-400">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Provision Modal -->
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
