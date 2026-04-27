<script setup lang="ts">
// ─── Executive / Org Settings ────────────────────────────────────────────────
// Tabbed: "Org Defaults" tab edits tenant-level rows; per-site override tabs
// edit site-specific rows that beat the org default for that site only.
// Sites without overrides don't get tabs (won't balloon for many-site orgs).
// Numeric prefs render a toggle + a number input. Required keys locked-on.
// Full design: docs/internal/org-settings-design.md
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    AdjustmentsHorizontalIcon,
    LockClosedIcon,
    BellAlertIcon,
    ClockIcon,
    InformationCircleIcon,
    CheckIcon,
    PlusIcon,
    XMarkIcon,
    ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

interface PreferenceEntry {
    key: string
    group: string
    label: string
    description: string
    status: 'required' | 'optional' | 'reserved'
    default: boolean
    enabled: boolean
    cms_ref: string | null
    wired: boolean
    kind?: 'boolean' | 'numeric'
    numeric_default?: number
    numeric_min?: number
    numeric_max?: number
    numeric_unit?: string
    value?: { days?: number } | null
    inherits_from_org?: boolean
}
type GroupedPrefs = Record<string, PreferenceEntry[]>
interface SiteSummary { id: number; name: string }
interface Props {
    orgGrouped: GroupedPrefs
    sites: SiteSummary[]
    sitesWithOverrides: number[]
    tenantName: string | null
    updatedAt: string
}
const props = defineProps<Props>()

// ── Tabs ────────────────────────────────────────────────────────────────────
const activeTab = ref<number | null>(null) // null = org defaults
type LocalEntry = boolean | { enabled: boolean; value: { days: number } | null }
type LocalState = Record<string, LocalEntry>
interface TabData { grouped: GroupedPrefs; initial: LocalState; state: LocalState; siteName?: string }
const tabs = ref<Record<string, TabData>>({})
const loadingSiteId = ref<number | null>(null)
const showSitePicker = ref(false)
const saving = ref(false)
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

const tabKey = (siteId: number | null) => siteId === null ? 'org' : `site_${siteId}`

function snapshotState(grouped: GroupedPrefs): LocalState {
    const out: LocalState = {}
    Object.values(grouped).flat().forEach(p => {
        if (p.kind === 'numeric') {
            out[p.key] = { enabled: p.enabled, value: p.value ?? { days: p.numeric_default ?? 0 } }
        } else {
            out[p.key] = p.enabled
        }
    })
    return out
}

onMounted(() => {
    tabs.value['org'] = {
        grouped: props.orgGrouped,
        initial: snapshotState(props.orgGrouped),
        state:   snapshotState(props.orgGrouped),
    }
    window.addEventListener('keydown', onKeydown)
    props.sitesWithOverrides.forEach(sid => loadSiteTab(sid))
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && showSitePicker.value) showSitePicker.value = false
}

async function loadSiteTab(siteId: number) {
    if (tabs.value[tabKey(siteId)]) return
    loadingSiteId.value = siteId
    try {
        const res = await axios.get(`/executive/org-settings/site/${siteId}`)
        tabs.value[tabKey(siteId)] = {
            grouped: res.data.grouped,
            initial: snapshotState(res.data.grouped),
            state:   snapshotState(res.data.grouped),
            siteName: res.data.siteName,
        }
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        errorMessage.value = err.response?.data?.message
            ?? `Could not load site ${siteId} (${err.response?.status ?? 'network'}).`
    } finally {
        loadingSiteId.value = null
    }
}

async function addSiteOverride(siteId: number) {
    showSitePicker.value = false
    await loadSiteTab(siteId)
    activeTab.value = siteId
}

const activeTabData = computed(() => tabs.value[tabKey(activeTab.value)])

const orderedGroupNames = [
    'Medical Director', 'Compliance Officer', 'Nursing Director',
    'Pharmacy Director', 'Social Work Supervisor', 'Program Director', 'Workflow',
]
const orderedGroupsForActive = computed(() => {
    if (! activeTabData.value) return []
    return orderedGroupNames
        .filter(g => Array.isArray(activeTabData.value!.grouped[g]))
        .map(g => ({ name: g, items: activeTabData.value!.grouped[g] }))
})

const dirtyKeys = computed<string[]>(() => {
    const data = activeTabData.value
    if (! data) return []
    return Object.keys(data.state).filter(k => {
        const a = data.state[k]; const b = data.initial[k]
        if (typeof a === 'boolean' && typeof b === 'boolean') return a !== b
        if (typeof a === 'object' && typeof b === 'object' && a && b) {
            return a.enabled !== b.enabled || (a.value?.days ?? null) !== (b.value?.days ?? null)
        }
        return true
    })
})

function toggle(entry: PreferenceEntry) {
    if (entry.status === 'required') return
    const data = activeTabData.value
    if (! data) return
    const current = data.state[entry.key]
    if (entry.kind === 'numeric' && typeof current === 'object' && current) {
        data.state[entry.key] = { enabled: ! current.enabled, value: current.value }
    } else {
        data.state[entry.key] = ! (current as boolean)
    }
    successMessage.value = null
    errorMessage.value = null
}

function setNumericDays(entry: PreferenceEntry, days: number) {
    const data = activeTabData.value
    if (! data) return
    const current = data.state[entry.key]
    if (typeof current !== 'object' || ! current) return
    data.state[entry.key] = { enabled: current.enabled, value: { days } }
    successMessage.value = null
    errorMessage.value = null
}

async function save() {
    const data = activeTabData.value
    if (! data || dirtyKeys.value.length === 0) return
    saving.value = true
    successMessage.value = null
    errorMessage.value = null

    const payload: Record<string, LocalEntry> = {}
    dirtyKeys.value.forEach(k => { payload[k] = data.state[k] })

    try {
        const res = await axios.post('/executive/org-settings', {
            preferences: payload,
            site_id:     activeTab.value,
        })
        successMessage.value = res.data.message ?? 'Saved.'
        dirtyKeys.value.forEach(k => { data.initial[k] = data.state[k] })
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        errorMessage.value = err.response?.data?.message
            ?? `Save failed (${err.response?.status ?? 'network error'}).`
    } finally {
        saving.value = false
    }
}

function discardChanges() {
    const data = activeTabData.value
    if (! data) return
    data.state = { ...data.initial }
    successMessage.value = null
    errorMessage.value = null
}

async function clearAllSiteOverrides() {
    const sid = activeTab.value
    if (sid === null) return
    if (! confirm('Revert this site to all org defaults? Removes every override row for this site.')) return
    saving.value = true
    errorMessage.value = null
    try {
        const data = activeTabData.value
        if (! data) return
        const overriddenKeys = Object.values(data.grouped).flat()
            .filter(p => p.inherits_from_org === false && p.status !== 'required')
            .map(p => p.key)
        await Promise.all(overriddenKeys.map(k =>
            axios.delete(`/executive/org-settings/site/${sid}/key/${encodeURIComponent(k)}`)
        ))
        delete tabs.value[tabKey(sid)]
        await loadSiteTab(sid)
        successMessage.value = 'All site overrides cleared.'
    } catch (e: unknown) {
        const err = e as { response?: { status?: number } }
        errorMessage.value = `Failed to clear overrides (${err.response?.status ?? 'network'}).`
    } finally {
        saving.value = false
    }
}

function closeSiteTab(siteId: number) {
    if (dirtyKeys.value.length > 0 && activeTab.value === siteId) {
        if (! confirm('Discard unsaved changes on this site tab?')) return
    }
    delete tabs.value[tabKey(siteId)]
    if (activeTab.value === siteId) activeTab.value = null
}

const sitesAvailableForOverride = computed(() =>
    props.sites.filter(s => ! tabs.value[tabKey(s.id)])
)

const statusBadge = (status: string) => {
    if (status === 'required') return { label: 'Required by CMS', cls: 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300' }
    if (status === 'reserved') return { label: 'Reserved', cls: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' }
    return { label: 'Optional', cls: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' }
}
const groupIcon = (group: string) => group === 'Workflow' ? ClockIcon : BellAlertIcon

function getNumericState(entry: PreferenceEntry): { enabled: boolean; days: number } {
    const data = activeTabData.value
    if (! data) return { enabled: false, days: entry.numeric_default ?? 0 }
    const v = data.state[entry.key]
    if (typeof v === 'object' && v) return { enabled: v.enabled, days: v.value?.days ?? entry.numeric_default ?? 0 }
    return { enabled: !!v, days: entry.numeric_default ?? 0 }
}
function getBooleanState(entry: PreferenceEntry): boolean {
    const data = activeTabData.value
    if (! data) return entry.enabled
    const v = data.state[entry.key]
    return typeof v === 'object' && v ? v.enabled : Boolean(v)
}
</script>

<template>
    <AppShell>
        <Head title="Org Settings" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <AdjustmentsHorizontalIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Org Settings
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        <span v-if="tenantName" class="font-medium">{{ tenantName }} — </span>
                        Org-wide preferences. Sites can override defaults individually via the tabs below.
                    </p>
                </div>
            </div>

            <!-- Help banner -->
            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-4 mb-6 flex items-start gap-3 text-sm">
                <InformationCircleIcon class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100 space-y-1">
                    <p><strong>How this works:</strong> the <em>Org Defaults</em> tab edits org-wide preferences. Use <em>Add site override</em> to create a per-site tab where Site Directors can deviate from the org default for their location only.</p>
                    <ul class="list-disc list-outside ml-5 text-blue-800 dark:text-blue-200">
                        <li><strong class="text-rose-700 dark:text-rose-300">Required by CMS</strong> — locked on, fires regardless of toggle.</li>
                        <li><strong class="text-blue-700 dark:text-blue-300">Optional</strong> — toggle controls behavior. Your choice.</li>
                        <li><strong class="text-amber-700 dark:text-amber-300">Reserved</strong> — preference saves; behavior activates when the alert detection is wired.</li>
                    </ul>
                </div>
            </div>

            <!-- Tabs row -->
            <div class="flex items-center gap-1 mb-4 border-b border-gray-200 dark:border-slate-700 overflow-x-auto" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === null"
                    @click="activeTab = null"
                    :class="[
                        'px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
                        activeTab === null
                            ? 'border-indigo-500 text-indigo-700 dark:text-indigo-300'
                            : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
                    ]"
                >
                    Org Defaults
                </button>

                <template v-for="siteId in Object.keys(tabs).filter(k => k.startsWith('site_')).map(k => Number(k.replace('site_','')))" :key="siteId">
                    <div class="flex items-center">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="activeTab === siteId"
                            @click="activeTab = siteId"
                            :class="[
                                'px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
                                activeTab === siteId
                                    ? 'border-indigo-500 text-indigo-700 dark:text-indigo-300'
                                    : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
                            ]"
                        >
                            {{ tabs[`site_${siteId}`]?.siteName ?? `Site #${siteId}` }}
                            <span class="text-xs text-gray-400 dark:text-slate-500 ml-1">override</span>
                        </button>
                        <button
                            type="button"
                            @click="closeSiteTab(siteId)"
                            class="text-gray-400 dark:text-slate-500 hover:text-rose-500 px-1"
                            :aria-label="`Close site ${siteId} tab`"
                        >
                            <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </template>

                <div class="ml-auto relative">
                    <button
                        v-if="sitesAvailableForOverride.length > 0"
                        type="button"
                        @click="showSitePicker = !showSitePicker"
                        class="text-xs px-3 py-1.5 rounded-lg border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors inline-flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-3.5 h-3.5" />
                        Add site override
                    </button>
                    <div v-if="showSitePicker"
                         class="absolute right-0 mt-1 w-64 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg z-20 max-h-72 overflow-y-auto"
                         role="menu"
                    >
                        <p class="px-3 py-2 text-xs text-gray-500 dark:text-slate-400 border-b border-gray-100 dark:border-slate-700">
                            Pick a site to customize:
                        </p>
                        <button
                            v-for="s in sitesAvailableForOverride"
                            :key="s.id"
                            type="button"
                            @click="addSiteOverride(s.id)"
                            class="block w-full text-left px-3 py-2 text-sm text-gray-800 dark:text-slate-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30"
                        >
                            {{ s.name }}
                            <span v-if="loadingSiteId === s.id" class="text-xs text-gray-400 ml-1">loading…</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Status messages -->
            <div v-if="successMessage" role="status" class="mb-4 rounded-lg border border-green-200 dark:border-green-900/40 bg-green-50 dark:bg-green-950/30 px-4 py-2 text-sm text-green-800 dark:text-green-300 flex items-center gap-2">
                <CheckIcon class="w-4 h-4" /> {{ successMessage }}
            </div>
            <div v-if="errorMessage" role="alert" class="mb-4 rounded-lg border border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/30 px-4 py-2 text-sm text-rose-800 dark:text-rose-300">
                {{ errorMessage }}
            </div>

            <div v-if="activeTab !== null" class="flex items-center justify-end mb-3">
                <button
                    type="button"
                    @click="clearAllSiteOverrides"
                    :disabled="saving"
                    class="text-xs text-gray-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 transition-colors inline-flex items-center gap-1"
                >
                    <ArrowUturnLeftIcon class="w-3.5 h-3.5" />
                    Revert all to org defaults
                </button>
            </div>

            <!-- Grouped preference cards -->
            <div class="space-y-6" v-if="activeTabData">
                <section
                    v-for="g in orderedGroupsForActive"
                    :key="g.name"
                    class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden"
                >
                    <header class="px-5 py-3 border-b border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-900/40 flex items-center gap-2">
                        <component :is="groupIcon(g.name)" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ g.name }}</h2>
                        <span class="ml-auto text-xs text-gray-500 dark:text-slate-400">{{ g.items.length }} preference{{ g.items.length === 1 ? '' : 's' }}</span>
                    </header>
                    <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                        <li
                            v-for="entry in g.items"
                            :key="entry.key"
                            class="px-5 py-4 flex items-start justify-between gap-4 transition-colors"
                            :class="getBooleanState(entry) && entry.status !== 'reserved'
                                ? 'bg-indigo-50/30 dark:bg-indigo-950/15'
                                : ''"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium text-gray-900 dark:text-slate-100">{{ entry.label }}</span>
                                    <span :class="statusBadge(entry.status).cls" class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded">
                                        {{ statusBadge(entry.status).label }}
                                    </span>
                                    <span v-if="entry.status === 'reserved' && !entry.wired"
                                        class="text-[10px] italic text-amber-700/80 dark:text-amber-400/80"
                                        title="Detection logic still being built. Your preference is saved.">
                                        — pending wiring
                                    </span>
                                    <span v-if="entry.status === 'required'" class="text-[10px] text-rose-700 dark:text-rose-400 inline-flex items-center gap-0.5" title="Locked by CMS regulation">
                                        <LockClosedIcon class="w-3 h-3" />
                                    </span>
                                    <span v-if="activeTab !== null && entry.inherits_from_org && entry.status !== 'required'"
                                        class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400"
                                        title="Inherits from org default — not yet overridden at this site">
                                        Inherits
                                    </span>
                                    <span v-else-if="activeTab !== null && !entry.inherits_from_org && entry.status !== 'required'"
                                        class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300"
                                        title="This site overrides the org default for this preference">
                                        Site override
                                    </span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-slate-400 mt-1">{{ entry.description }}</p>
                                <p v-if="entry.cms_ref" class="text-[10px] text-gray-500 dark:text-slate-500 mt-1 font-mono">{{ entry.cms_ref }}</p>

                                <div v-if="entry.kind === 'numeric'" class="mt-2 flex items-center gap-2">
                                    <input
                                        type="number"
                                        :value="getNumericState(entry).days"
                                        :min="entry.numeric_min ?? 1"
                                        :max="entry.numeric_max ?? 999"
                                        :disabled="!getNumericState(entry).enabled || entry.status === 'required'"
                                        @input="(e) => setNumericDays(entry, Number((e.target as HTMLInputElement).value))"
                                        class="w-24 px-2 py-1 text-sm rounded border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 disabled:opacity-50"
                                        :aria-label="`Number of ${entry.numeric_unit ?? 'days'} for ${entry.label}`"
                                    />
                                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ entry.numeric_unit ?? 'days' }}</span>
                                </div>
                            </div>

                            <button
                                type="button"
                                :disabled="entry.status === 'required'"
                                :aria-pressed="getBooleanState(entry)"
                                :aria-label="`Toggle ${entry.label}`"
                                @click="toggle(entry)"
                                :class="[
                                    'relative inline-flex shrink-0 h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800',
                                    getBooleanState(entry)
                                        ? (entry.status === 'required' ? 'bg-rose-400 dark:bg-rose-700' : 'bg-indigo-600')
                                        : 'bg-gray-300 dark:bg-slate-600',
                                    entry.status === 'required' ? 'cursor-not-allowed opacity-80' : 'cursor-pointer',
                                ]"
                            >
                                <span
                                    :class="[
                                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                        getBooleanState(entry) ? 'translate-x-6' : 'translate-x-1',
                                    ]"
                                />
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
            <div v-else class="text-center py-12 text-gray-500 dark:text-slate-400">Loading…</div>

            <!-- Save bar -->
            <div
                v-if="dirtyKeys.length > 0"
                class="sticky bottom-4 mt-6 z-10 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 shadow-lg px-5 py-3 flex items-center justify-between gap-4"
                role="region"
                aria-label="Unsaved changes"
            >
                <div class="text-sm text-gray-700 dark:text-slate-300">
                    <strong>{{ dirtyKeys.length }}</strong> unsaved change{{ dirtyKeys.length === 1 ? '' : 's' }}
                    <span v-if="activeTab !== null" class="text-gray-500 dark:text-slate-400">on this site override</span>
                    <span v-else class="text-gray-500 dark:text-slate-400">on org defaults</span>.
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="discardChanges"
                        class="text-sm px-3 py-2 rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">
                        Discard
                    </button>
                    <button type="button" @click="save" :disabled="saving"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
                        <CheckIcon class="w-4 h-4" />
                        {{ saving ? 'Saving…' : 'Save changes' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
