<script setup lang="ts">
// ─── Executive / Site Settings ────────────────────────────────────────────────
// Executive-level page where org leadership controls OPTIONAL notification +
// workflow routing. Required-by-CMS items are shown but locked-on; tenant
// preferences toggle on/off and save in bulk. Full design + catalog:
// docs/internal/site-settings-design.md
//
// Audience: super_admin OR (department=executive AND role=admin).
//
// Notable rules:
//   - Required keys cannot be disabled — backend silently no-ops the attempt
//     and the UI greys the toggle.
//   - Save commits a single bulk update; the IT-admin audit log records one
//     row per actually-changed preference.
//   - Reserved keys (status='reserved') are toggleable; saved preference
//     activates when the underlying alert detection is wired.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
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
}

interface Props {
    grouped: Record<string, PreferenceEntry[]>
    tenantName: string | null
    updatedAt: string
}

const props = defineProps<Props>()

// ── Local state mirrors the props on mount; user toggles update locally ────
type LocalState = Record<string, boolean>
const initialState: LocalState = (() => {
    const out: LocalState = {}
    Object.values(props.grouped).flat().forEach(p => { out[p.key] = p.enabled })
    return out
})()
const state = ref<LocalState>({ ...initialState })

const saving = ref(false)
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

// ── Computed: which keys differ from the stored state? Drives Save button ──
const dirtyKeys = computed<string[]>(() => {
    return Object.keys(state.value).filter(k => state.value[k] !== initialState[k])
})

// Group display order — Required + designation groups first, Workflow last.
const groupOrder: string[] = [
    'Medical Director',
    'Compliance Officer',
    'Nursing Director',
    'Pharmacy Director',
    'Social Work Supervisor',
    'Program Director',
    'Workflow',
]

const orderedGroups = computed(() => {
    return groupOrder
        .filter(g => Array.isArray(props.grouped[g]))
        .map(g => ({ name: g, items: props.grouped[g] }))
})

// ── Toggle handler — Required keys are no-ops in the UI ────────────────────
function toggle(entry: PreferenceEntry) {
    if (entry.status === 'required') return
    state.value[entry.key] = ! state.value[entry.key]
    successMessage.value = null
    errorMessage.value = null
}

async function save() {
    if (dirtyKeys.value.length === 0) return
    saving.value = true
    successMessage.value = null
    errorMessage.value = null

    // Only send the changed keys (smaller payload, audit-log clarity).
    const payload: LocalState = {}
    dirtyKeys.value.forEach(k => { payload[k] = state.value[k] })

    try {
        const res = await axios.post('/executive/site-settings', { preferences: payload })
        successMessage.value = res.data.message ?? 'Saved.'
        // Re-baseline initialState so the dirty check zeros out
        dirtyKeys.value.forEach(k => { initialState[k] = state.value[k] })
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string }, status?: number } }
        errorMessage.value = err.response?.data?.message
            ?? `Save failed (${err.response?.status ?? 'network error'}).`
    } finally {
        saving.value = false
    }
}

function discardChanges() {
    state.value = { ...initialState }
    successMessage.value = null
    errorMessage.value = null
}

// ── Status helpers for badge rendering ─────────────────────────────────────
const statusBadge = (status: string) => {
    if (status === 'required') {
        return { label: 'Required by CMS', cls: 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300' }
    }
    if (status === 'reserved') {
        return { label: 'Reserved', cls: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' }
    }
    return { label: 'Optional', cls: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' }
}

// Group icon mapping (icon names align with the heroicons we imported)
const groupIcon = (group: string) => {
    if (group === 'Workflow') return ClockIcon
    return BellAlertIcon
}
</script>

<template>
    <AppShell>
        <Head title="Site Settings" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <AdjustmentsHorizontalIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Site Settings
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        <span v-if="tenantName" class="font-medium">{{ tenantName }} — </span>
                        Org-wide preferences for optional notifications + workflow routing.
                    </p>
                </div>
            </div>

            <!-- Help banner -->
            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-4 mb-6 flex items-start gap-3 text-sm">
                <InformationCircleIcon class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100 space-y-1">
                    <p><strong>How this works:</strong> each toggle controls whether a specific notification or workflow event fires for designated recipients in your organization.</p>
                    <ul class="list-disc list-outside ml-5 text-blue-800 dark:text-blue-200">
                        <li><strong class="text-rose-700 dark:text-rose-300">Required by CMS</strong> — fires regardless of this setting (locked on).</li>
                        <li><strong class="text-blue-700 dark:text-blue-300">Optional</strong> — fires when toggled on. Your choice.</li>
                        <li><strong class="text-amber-700 dark:text-amber-300">Reserved</strong> — your preference saves; the underlying alert detection is being built. Once wired in code, it will respect your saved state automatically.</li>
                    </ul>
                </div>
            </div>

            <!-- Status messages -->
            <div v-if="successMessage" role="status" class="mb-4 rounded-lg border border-green-200 dark:border-green-900/40 bg-green-50 dark:bg-green-950/30 px-4 py-2 text-sm text-green-800 dark:text-green-300 flex items-center gap-2">
                <CheckIcon class="w-4 h-4" /> {{ successMessage }}
            </div>
            <div v-if="errorMessage" role="alert" class="mb-4 rounded-lg border border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/30 px-4 py-2 text-sm text-rose-800 dark:text-rose-300">
                {{ errorMessage }}
            </div>

            <!-- Grouped preference cards -->
            <div class="space-y-6">
                <section
                    v-for="g in orderedGroups"
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
                            :class="state[entry.key] && entry.status !== 'reserved'
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
                                </div>
                                <p class="text-xs text-gray-600 dark:text-slate-400 mt-1">{{ entry.description }}</p>
                                <p v-if="entry.cms_ref" class="text-[10px] text-gray-500 dark:text-slate-500 mt-1 font-mono">{{ entry.cms_ref }}</p>
                            </div>

                            <!-- Toggle -->
                            <button
                                type="button"
                                :disabled="entry.status === 'required'"
                                :aria-pressed="state[entry.key]"
                                :aria-label="`Toggle ${entry.label}`"
                                @click="toggle(entry)"
                                :class="[
                                    'relative inline-flex shrink-0 h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800',
                                    state[entry.key]
                                        ? (entry.status === 'required' ? 'bg-rose-400 dark:bg-rose-700' : 'bg-indigo-600')
                                        : 'bg-gray-300 dark:bg-slate-600',
                                    entry.status === 'required' ? 'cursor-not-allowed opacity-80' : 'cursor-pointer',
                                ]"
                            >
                                <span
                                    :class="[
                                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                        state[entry.key] ? 'translate-x-6' : 'translate-x-1',
                                    ]"
                                />
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Save bar (sticky at bottom while there are unsaved changes) -->
            <div
                v-if="dirtyKeys.length > 0"
                class="sticky bottom-4 mt-6 z-10 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 shadow-lg px-5 py-3 flex items-center justify-between gap-4"
                role="region"
                aria-label="Unsaved changes"
            >
                <div class="text-sm text-gray-700 dark:text-slate-300">
                    <strong>{{ dirtyKeys.length }}</strong> unsaved change{{ dirtyKeys.length === 1 ? '' : 's' }}.
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="discardChanges"
                        class="text-sm px-3 py-2 rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors"
                    >
                        Discard
                    </button>
                    <button
                        type="button"
                        @click="save"
                        :disabled="saving"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50 transition-colors"
                    >
                        <CheckIcon class="w-4 h-4" />
                        {{ saving ? 'Saving…' : 'Save changes' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
