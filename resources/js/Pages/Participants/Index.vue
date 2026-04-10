<script setup lang="ts">
// ─── Participants/Index.vue ───────────────────────────────────────────────────
// The PACE participant directory. Shows a searchable, filterable table of all
// participants for the current tenant. Clicking a row opens the participant's
// full profile. Enrollment-dept users see an "Add Participant" button.
// IDT reassessment overdue badge enforces 42 CFR §460.104(c) (6-month rule).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Flag {
    flag_type: string
    label: string
    severity: 'low' | 'medium' | 'high' | 'critical'
}

interface Participant {
    id: number
    mrn: string
    first_name: string
    last_name: string
    preferred_name: string | null
    dob: string
    enrollment_status: string
    is_active: boolean
    photo_path: string | null
    site: { id: number; name: string }
    active_flags: Flag[]
    idt_review_overdue: boolean
}

interface PaginatorLink {
    url: string | null
    label: string
    active: boolean
}

interface Paginator<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: PaginatorLink[]
}

const props = defineProps<{
    participants: Paginator<Participant>
    sites: { id: number; name: string }[]
    filters: { search?: string; status?: string; site_id?: string; has_flags?: string }
    canCreate: boolean
}>()

// ── Filter state ───────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const siteId = ref(props.filters.site_id ?? '')
const hasFlags = ref(props.filters.has_flags === '1')

const hasActiveFilters = computed(
    () => search.value || status.value || siteId.value || hasFlags.value,
)

function applyFilters(overrides: Record<string, string | boolean> = {}) {
    router.get(
        '/participants',
        {
            search: overrides.search ?? search.value,
            status: overrides.status ?? status.value,
            site_id: overrides.site_id ?? siteId.value,
            has_flags: overrides.has_flags ?? (hasFlags.value ? '1' : ''),
        },
        { preserveState: true, replace: true },
    )
}

function handleSearch(e: Event) {
    e.preventDefault()
    applyFilters()
}

function onStatusChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value
    status.value = val
    applyFilters({ status: val })
}

function onSiteChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value
    siteId.value = val
    applyFilters({ site_id: val })
}

function onFlagsChange(e: Event) {
    const checked = (e.target as HTMLInputElement).checked
    hasFlags.value = checked
    applyFilters({ has_flags: checked ? '1' : '' })
}

function clearFilters() {
    search.value = ''
    status.value = ''
    siteId.value = ''
    hasFlags.value = false
    router.get('/participants', {}, { preserveState: false })
}

// ── Display helpers ────────────────────────────────────────────────────────────

// Laravel serializes date fields as full ISO timestamps — slice to date part
// to avoid "Invalid Date" when constructing with 'T12:00:00' appended.
function parseDate(val: string | null | undefined): Date | null {
    if (!val) return null
    return new Date(val.slice(0, 10) + 'T12:00:00')
}

function fmtDate(val: string | null | undefined): string {
    const d = parseDate(val)
    return d ? d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-'
}

function calcAge(dob: string): number {
    const d = parseDate(dob) ?? new Date()
    const now = new Date()
    let a = now.getFullYear() - d.getFullYear()
    if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
    return a
}

function rowBg(s: string): string {
    if (s === 'deceased') return 'bg-gray-50 dark:bg-slate-700/50 opacity-60'
    if (s === 'disenrolled') return 'bg-gray-50 dark:bg-slate-700/50'
    return 'bg-white dark:bg-slate-800'
}

// ── Color maps ─────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    enrolled: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    referred: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
    intake: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
    pending: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
    disenrolled: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
    deceased: 'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const FLAG_COLORS: Record<string, string> = {
    low: 'bg-blue-500 text-white',
    medium: 'bg-amber-500 text-white',
    high: 'bg-orange-500 text-white',
    critical: 'bg-red-600 text-white',
}

const STATUSES = ['enrolled', 'referred', 'intake', 'pending', 'disenrolled', 'deceased']
</script>

<template>
    <Head title="Participants" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                Participant Directory
            </h1>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header ── -->
            <div class="flex items-center justify-between mb-5">
                <p class="text-sm text-gray-500 dark:text-slate-400">
                    {{ participants.total.toLocaleString() }}
                    participant{{ participants.total !== 1 ? 's' : '' }}
                </p>
                <a
                    v-if="canCreate"
                    href="/participants/create"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    Add Participant
                </a>
            </div>

            <!-- ── Search + filter bar ── -->
            <form class="flex flex-wrap items-center gap-2 mb-4" @submit.prevent="handleSearch">
                <!-- Search input -->
                <div class="relative flex-1 min-w-[260px]">
                    <MagnifyingGlassIcon
                        class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500"
                        aria-hidden="true"
                    />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by name, MRN, or DOB (YYYY-MM-DD)"
                        aria-label="Search participants"
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                    />
                </div>

                <!-- Status filter -->
                <select name="select"
                    :value="status"
                    aria-label="Filter by status"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onStatusChange"
                >
                    <option value="">All Statuses</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">
                        {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                    </option>
                </select>

                <!-- Site filter -->
                <select name="select"
                    :value="siteId"
                    aria-label="Filter by site"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onSiteChange"
                >
                    <option value="">All Sites</option>
                    <option v-for="s in sites" :key="s.id" :value="String(s.id)">
                        {{ s.name }}
                    </option>
                </select>

                <!-- Flags checkbox -->
                <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
                    <input
                        type="checkbox"
                        :checked="hasFlags"
                        class="rounded border-gray-300 text-blue-600 dark:text-blue-400"
                        @change="onFlagsChange"
                    />
                    Active flags only
                </label>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 rounded-lg font-medium transition-colors"
                >
                    Search
                </button>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 underline"
                    @click="clearFilters"
                >
                    Clear
                </button>
            </form>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm" aria-label="Participant directory">
                    <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                        <tr>
                            <th
                                v-for="col in ['MRN', 'Name', 'DOB / Age', 'Status', 'Flags', 'Site']"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="participants.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                No participants found.
                            </td>
                        </tr>

                        <!-- Participant rows -->
                        <tr
                            v-for="ppt in participants.data"
                            :key="ppt.id"
                            :class="[rowBg(ppt.enrollment_status), 'hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors']"
                            tabindex="0"
                            :aria-label="`Open profile for ${ppt.last_name}, ${ppt.first_name}`"
                            @click="router.visit(`/participants/${ppt.id}`)"
                            @keydown.enter="router.visit(`/participants/${ppt.id}`)"
                        >
                            <!-- MRN -->
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-700 dark:text-slate-300">
                                {{ ppt.mrn }}
                            </td>

                            <!-- Name + avatar -->
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <img
                                        v-if="ppt.photo_path"
                                        :src="`/storage/${ppt.photo_path}`"
                                        alt=""
                                        class="w-8 h-8 rounded-full object-cover flex-shrink-0 border border-gray-200 dark:border-slate-600"
                                    />
                                    <div
                                        v-else
                                        class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-[11px] font-semibold flex-shrink-0"
                                        aria-hidden="true"
                                    >
                                        {{ (ppt.first_name?.[0] ?? '?').toUpperCase() }}{{ (ppt.last_name?.[0] ?? '').toUpperCase() }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-900 dark:text-slate-100">
                                            {{ ppt.last_name }}, {{ ppt.first_name }}
                                        </div>
                                        <div
                                            v-if="ppt.preferred_name"
                                            class="text-xs text-gray-400 dark:text-slate-500"
                                        >
                                            "{{ ppt.preferred_name }}"
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- DOB / Age -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                <div>{{ fmtDate(ppt.dob) }}</div>
                                <div class="text-xs text-gray-400 dark:text-slate-500">
                                    {{ calcAge(ppt.dob) }} yrs
                                </div>
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', STATUS_COLORS[ppt.enrollment_status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300']"
                                >
                                    {{ ppt.enrollment_status }}
                                </span>
                            </td>

                            <!-- Flags -->
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="(f, i) in ppt.active_flags.slice(0, 4)"
                                        :key="i"
                                        :class="['inline-flex px-2.5 py-1 rounded-full text-xs font-semibold shadow-sm', FLAG_COLORS[f.severity] ?? 'bg-slate-500 text-white']"
                                        :title="f.label"
                                    >
                                        {{ f.label }}
                                    </span>
                                    <span
                                        v-if="ppt.active_flags.length > 4"
                                        class="text-xs text-gray-400 dark:text-slate-500"
                                    >
                                        +{{ ppt.active_flags.length - 4 }}
                                    </span>
                                    <!-- IDT reassessment overdue — 42 CFR §460.104(c) -->
                                    <span
                                        v-if="ppt.idt_review_overdue"
                                        class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300"
                                        title="IDT reassessment overdue (42 CFR §460.104(c) — 6-month required)"
                                    >
                                        IDT Due
                                    </span>
                                </div>
                            </td>

                            <!-- Site -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-xs">
                                {{ ppt.site?.name }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ── -->
            <div
                v-if="participants.last_page > 1"
                class="flex items-center justify-between mt-4 text-sm"
            >
                <span class="text-gray-500 dark:text-slate-400">
                    Showing
                    {{ ((participants.current_page - 1) * participants.per_page) + 1 }}&ndash;{{
                        Math.min(participants.current_page * participants.per_page, participants.total)
                    }}
                    of {{ participants.total }}
                </span>
                <div class="flex gap-1" role="navigation" aria-label="Pagination">
                    <button
                        v-for="(link, i) in participants.links"
                        :key="i"
                        :disabled="!link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        :class="[
                            'px-3 py-1.5 rounded-md border text-xs font-medium transition-colors',
                            link.active
                                ? 'bg-blue-600 text-white border-blue-600'
                                : link.url
                                    ? 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/50'
                                    : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed',
                        ]"
                        @click="link.url && router.visit(link.url, { preserveState: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AppShell>
</template>
