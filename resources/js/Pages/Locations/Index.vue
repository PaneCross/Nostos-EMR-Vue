<script setup lang="ts">
// ─── Locations/Index ──────────────────────────────────────────────────────────
// Management page for service locations used in appointment scheduling.
// Transportation team only may create/edit/archive. All depts can read.
// Data: server-side Inertia props (locations, location_types, can_write).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import {
    PlusIcon,
    PencilIcon,
    ArchiveBoxIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Location {
    id: number
    name: string
    label: string | null
    location_type: string
    type_label: string
    street: string | null
    city: string | null
    state: string | null
    zip: string | null
    phone: string | null
    contact_name: string | null
    notes: string | null
    is_active: boolean
    deleted_at: string | null
}

interface FormState {
    name: string
    label: string
    location_type: string
    street: string
    city: string
    state: string
    zip: string
    phone: string
    contact_name: string
    notes: string
    is_active: boolean
}

interface Props {
    locations: Location[]
    location_types: Record<string, string>
    can_write: boolean
}

const props = defineProps<Props>()

// ── Type color map ─────────────────────────────────────────────────────────────

const TYPE_COLORS: Record<string, string> = {
    pace_center: 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
    acs_location:
        'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 ring-purple-600/20',
    dialysis: 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 ring-red-600/20',
    specialist:
        'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    hospital: 'bg-orange-50 text-orange-700 ring-orange-600/20',
    pharmacy:
        'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
    lab: 'bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 ring-teal-600/20',
    day_program:
        'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 ring-indigo-600/20',
    other_external:
        'bg-slate-50 dark:bg-slate-900 text-slate-700 dark:text-slate-300 ring-slate-600/20',
}

function typeColor(locationType: string): string {
    return (
        TYPE_COLORS[locationType] ??
        'bg-slate-50 dark:bg-slate-900 text-slate-700 dark:text-slate-300 ring-slate-600/20'
    )
}

// ── State ─────────────────────────────────────────────────────────────────────

const filterType = ref<string>('all')
const filterActive = ref<'active' | 'inactive' | 'all'>('active')
const modal = ref<'none' | 'create' | 'edit'>('none')
const editing = ref<Location | null>(null)
const saving = ref(false)
const confirmArchive = ref<Location | null>(null)

const blankForm = (): FormState => ({
    name: '',
    label: '',
    location_type: 'pace_center',
    street: '',
    city: '',
    state: '',
    zip: '',
    phone: '',
    contact_name: '',
    notes: '',
    is_active: true,
})

const form = ref<FormState>(blankForm())
const errors = ref<Partial<Record<keyof FormState, string>>>({})

// ── Filtered list ─────────────────────────────────────────────────────────────

const filtered = computed(() => {
    return props.locations.filter((l) => {
        if (filterType.value !== 'all' && l.location_type !== filterType.value) return false
        if (filterActive.value === 'active' && (!l.is_active || l.deleted_at)) return false
        if (filterActive.value === 'inactive' && l.is_active && !l.deleted_at) return false
        return true
    })
})

// ── Address helper ────────────────────────────────────────────────────────────

function formatAddress(loc: Location): string {
    if (!loc.street) return ''
    return `${loc.street}, ${loc.city ?? ''} ${loc.state ?? ''} ${loc.zip ?? ''}`.trim()
}

// ── Modal helpers ─────────────────────────────────────────────────────────────

function openCreate() {
    form.value = blankForm()
    errors.value = {}
    modal.value = 'create'
}

function openEdit(loc: Location) {
    editing.value = loc
    form.value = {
        name: loc.name,
        label: loc.label ?? '',
        location_type: loc.location_type,
        street: loc.street ?? '',
        city: loc.city ?? '',
        state: loc.state ?? '',
        zip: loc.zip ?? '',
        phone: loc.phone ?? '',
        contact_name: loc.contact_name ?? '',
        notes: loc.notes ?? '',
        is_active: loc.is_active,
    }
    errors.value = {}
    modal.value = 'edit'
}

function closeModal() {
    modal.value = 'none'
    editing.value = null
}

function clearError(field: keyof FormState) {
    const e = { ...errors.value }
    delete e[field]
    errors.value = e
}

// ── Save ──────────────────────────────────────────────────────────────────────

function handleSave() {
    const e: Partial<Record<keyof FormState, string>> = {}
    if (!form.value.name.trim()) e.name = 'Name is required.'
    if (!form.value.location_type) e.location_type = 'Type is required.'
    if (Object.keys(e).length) {
        errors.value = e
        return
    }

    saving.value = true
    const payload = { ...form.value }

    if (modal.value === 'create') {
        router.post('/locations', payload, {
            onSuccess: () => {
                saving.value = false
                closeModal()
            },
            onError: (errs) => {
                errors.value = errs as typeof errors.value
                saving.value = false
            },
            onFinish: () => {
                saving.value = false
            },
        })
    } else if (editing.value) {
        router.put(`/locations/${editing.value.id}`, payload, {
            onSuccess: () => {
                saving.value = false
                closeModal()
            },
            onError: (errs) => {
                errors.value = errs as typeof errors.value
                saving.value = false
            },
            onFinish: () => {
                saving.value = false
            },
        })
    }
}

// ── Archive ───────────────────────────────────────────────────────────────────

function handleArchive(loc: Location) {
    router.delete(`/locations/${loc.id}`, {
        onFinish: () => {
            confirmArchive.value = null
        },
    })
}
</script>

<template>
    <AppShell>
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Locations</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Service locations used for appointment scheduling across the PACE program.
                </p>
            </div>
            <button
                v-if="can_write"
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 transition-colors"
                @click="openCreate"
            >
                <PlusIcon class="w-4 h-4" />
                Add Location
            </button>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <select
                v-model="filterType"
                class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-700 dark:text-slate-300 shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
            >
                <option value="all">All Types</option>
                <option v-for="(label, key) in location_types" :key="key" :value="key">
                    {{ label }}
                </option>
            </select>

            <div
                class="flex rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden shadow-sm text-sm"
            >
                <button
                    v-for="opt in ['active', 'inactive', 'all'] as const"
                    :key="opt"
                    class="px-3 py-1.5 capitalize transition-colors"
                    :class="
                        filterActive === opt
                            ? 'bg-blue-600 text-white font-medium'
                            : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'
                    "
                    @click="filterActive = opt"
                >
                    {{ opt }}
                </button>
            </div>

            <span class="text-xs text-slate-400 ml-auto">
                {{ filtered.length }} location{{ filtered.length !== 1 ? 's' : '' }}
            </span>
        </div>

        <!-- Table -->
        <div
            class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm"
        >
            <div v-if="filtered.length === 0" class="py-16 text-center text-slate-400 text-sm">
                No locations match the current filters.
            </div>
            <table v-else class="w-full text-sm">
                <thead
                    class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide"
                >
                    <tr>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Type</th>
                        <th class="px-4 py-3 text-left">Address</th>
                        <th class="px-4 py-3 text-left">Phone</th>
                        <th class="px-4 py-3 text-left">Contact</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th v-if="can_write" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <tr
                        v-for="loc in filtered"
                        :key="loc.id"
                        class="hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
                        :class="{ 'opacity-50': loc.deleted_at }"
                    >
                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                            {{ loc.name }}
                            <span v-if="loc.label" class="ml-1.5 text-xs text-slate-400"
                                >({{ loc.label }})</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="typeColor(loc.location_type)"
                            >
                                {{ loc.type_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                            <span v-if="loc.street">{{ formatAddress(loc) }}</span>
                            <span v-else class="text-slate-400 italic">No address</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {{ loc.phone ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {{ loc.contact_name ?? '-' }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="loc.deleted_at"
                                class="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-500 dark:text-slate-400"
                                >Archived</span
                            >
                            <span
                                v-else-if="loc.is_active"
                                class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-950/60 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300"
                                >Active</span
                            >
                            <span
                                v-else
                                class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-950/60 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300"
                                >Inactive</span
                            >
                        </td>
                        <td v-if="can_write" class="px-4 py-3 text-right">
                            <div v-if="!loc.deleted_at" class="flex items-center justify-end gap-2">
                                <button
                                    class="text-slate-400 hover:text-blue-600 transition-colors"
                                    title="Edit"
                                    @click="openEdit(loc)"
                                >
                                    <PencilIcon class="w-4 h-4" />
                                </button>
                                <button
                                    class="text-slate-400 hover:text-red-600 transition-colors"
                                    title="Archive"
                                    @click="confirmArchive = loc"
                                >
                                    <ArchiveBoxIcon class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create / Edit Modal -->
        <div
            v-if="modal !== 'none'"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white dark:bg-slate-800 shadow-xl">
                <div
                    class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 px-6 py-4"
                >
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ modal === 'create' ? 'Add Location' : 'Edit Location' }}
                    </h2>
                    <button
                        class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                        @click="closeModal"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <!-- Name + Type -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                            >
                                Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none dark:bg-slate-700 dark:border-slate-600"
                                :class="
                                    errors.name
                                        ? 'border-red-400'
                                        : 'border-slate-300 dark:border-slate-600'
                                "
                                placeholder="Sunrise PACE Center"
                                @input="clearError('name')"
                            />
                            <p v-if="errors.name" class="text-xs text-red-500 mt-1">
                                {{ errors.name }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                            >
                                Type <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.location_type"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                @change="clearError('location_type')"
                            >
                                <option
                                    v-for="(label, key) in location_types"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Short Label -->
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                        >
                            Short Label <span class="text-slate-400 font-normal">(optional)</span>
                        </label>
                        <input
                            v-model="form.label"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            placeholder="PACE East"
                        />
                    </div>

                    <!-- Street -->
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                            >Street Address</label
                        >
                        <input
                            v-model="form.street"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            placeholder="123 Main St"
                        />
                    </div>

                    <!-- City / State / ZIP -->
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                                >City</label
                            >
                            <input
                                v-model="form.city"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                                >State</label
                            >
                            <input
                                v-model="form.state"
                                maxlength="2"
                                placeholder="CA"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                                >ZIP</label
                            >
                            <input
                                v-model="form.zip"
                                placeholder="90210"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            />
                        </div>
                    </div>

                    <!-- Phone + Contact -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                                >Phone</label
                            >
                            <input
                                v-model="form.phone"
                                placeholder="(555) 555-0100"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                                >Contact Name</label
                            >
                            <input
                                v-model="form.contact_name"
                                placeholder="Jane Smith"
                                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            />
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1"
                            >Notes</label
                        >
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            placeholder="Any relevant notes about this location..."
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none resize-none"
                        ></textarea>
                    </div>

                    <!-- Active toggle -->
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span class="text-sm text-slate-700 dark:text-slate-300"
                            >Active (visible in scheduling dropdowns)</span
                        >
                    </label>
                </div>

                <div
                    class="flex justify-end gap-3 border-t border-slate-200 dark:border-slate-700 px-6 py-4"
                >
                    <button
                        class="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
                        @click="closeModal"
                    >
                        Cancel
                    </button>
                    <button
                        :disabled="saving"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
                        @click="handleSave"
                    >
                        {{
                            saving
                                ? 'Saving...'
                                : modal === 'create'
                                  ? 'Add Location'
                                  : 'Save Changes'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Archive Confirm -->
        <div
            v-if="confirmArchive"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
            <div
                class="w-full max-w-sm rounded-2xl bg-white dark:bg-slate-800 shadow-xl p-6 text-center"
            >
                <div
                    class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/60"
                >
                    <ExclamationTriangleIcon class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                </div>
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">
                    Archive Location?
                </h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">
                    <strong>{{ confirmArchive.name }}</strong> will be archived and removed from
                    scheduling dropdowns. Existing appointments are not affected.
                </p>
                <div class="flex gap-3">
                    <button
                        class="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700"
                        @click="confirmArchive = null"
                    >
                        Cancel
                    </button>
                    <button
                        class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                        @click="handleArchive(confirmArchive)"
                    >
                        Archive
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
