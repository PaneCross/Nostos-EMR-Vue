<!--
  LocationCombobox: filterable dropdown for selecting an appointment location.
  Groups locations into "PACE Sites" (where site_id != null) first, then by
  location_type (Hospital, Dialysis, Specialist, Lab, Pharmacy, Day Program, ACS,
  Other). Each group sorted alphabetically by name. Partial-match filter on typing
  across name and city. Keyboard navigable (↑ ↓ Enter Esc). Emits update:modelValue.
-->
<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { ChevronDownIcon, XMarkIcon, BuildingOffice2Icon } from '@heroicons/vue/24/outline'

interface LocationOption {
    id: number
    name: string
    location_type: string
    site_id: number | null
    city?: string | null
}

const props = withDefaults(defineProps<{
    locations: LocationOption[]
    modelValue: number | string | null
    placeholder?: string
}>(), {
    placeholder: 'Select a location...',
})

const emit = defineEmits<{
    'update:modelValue': [value: number | null]
}>()

// ── Label map: matches Location::TYPE_LABELS server-side ─────────────────────
const TYPE_LABELS: Record<string, string> = {
    pace_center:    'PACE Center',
    acs_location:   'ACS Location',
    dialysis:       'Dialysis Center',
    specialist:     'Specialist Office',
    hospital:       'Hospital',
    pharmacy:       'Pharmacy',
    lab:            'Laboratory',
    day_program:    'Day Program',
    other_external: 'Other External',
}

// ── State ─────────────────────────────────────────────────────────────────────
const open = ref(false)
const query = ref('')
const highlightedIndex = ref(-1)
const rootRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)

// ── Selected location (from modelValue) ──────────────────────────────────────
const selected = computed<LocationOption | null>(() => {
    const id = Number(props.modelValue)
    if (!id) return null
    return props.locations.find(l => l.id === id) ?? null
})

// ── Filtered + grouped locations ─────────────────────────────────────────────
interface Group {
    label: string
    isPace: boolean
    items: LocationOption[]
}

const groups = computed<Group[]>(() => {
    const q = query.value.trim().toLowerCase()

    // Filter first
    const filtered = !q
        ? props.locations
        : props.locations.filter(l =>
            l.name.toLowerCase().includes(q)
            || (l.city ?? '').toLowerCase().includes(q)
        )

    // PACE sites (site_id != null): one combined group, sorted by name
    const pace = filtered
        .filter(l => l.site_id != null)
        .sort((a, b) => a.name.localeCompare(b.name))

    // Everything else grouped by location_type
    const others = filtered.filter(l => l.site_id == null)
    const byType = new Map<string, LocationOption[]>()
    for (const l of others) {
        const type = l.location_type
        if (!byType.has(type)) byType.set(type, [])
        byType.get(type)!.push(l)
    }

    const result: Group[] = []
    if (pace.length > 0) result.push({ label: 'PACE Sites', isPace: true, items: pace })

    // Sort other groups alphabetically by label, sort items inside each group alphabetically
    const typeGroups = [...byType.entries()]
        .map(([type, items]) => ({
            label: TYPE_LABELS[type] ?? type,
            isPace: false,
            items: items.sort((a, b) => a.name.localeCompare(b.name)),
        }))
        .sort((a, b) => a.label.localeCompare(b.label))

    return [...result, ...typeGroups]
})

// Flat list of visible options for keyboard nav
const flatItems = computed<LocationOption[]>(() =>
    groups.value.flatMap(g => g.items)
)

// ── Open / close ──────────────────────────────────────────────────────────────
function openDropdown() {
    open.value = true
    highlightedIndex.value = 0
    // Focus the search input on open
    requestAnimationFrame(() => inputRef.value?.focus())
}

function closeDropdown() {
    open.value = false
    query.value = ''
    highlightedIndex.value = -1
}

function toggle() {
    if (open.value) closeDropdown()
    else openDropdown()
}

// ── Select / clear ────────────────────────────────────────────────────────────
function selectItem(item: LocationOption) {
    emit('update:modelValue', item.id)
    closeDropdown()
}

function clearSelection() {
    emit('update:modelValue', null)
    query.value = ''
}

// ── Keyboard nav ──────────────────────────────────────────────────────────────
function onKeydown(e: KeyboardEvent) {
    if (!open.value) return
    const items = flatItems.value

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        if (items.length === 0) return
        highlightedIndex.value = (highlightedIndex.value + 1) % items.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        if (items.length === 0) return
        highlightedIndex.value = (highlightedIndex.value - 1 + items.length) % items.length
    } else if (e.key === 'Enter') {
        e.preventDefault()
        const item = items[highlightedIndex.value]
        if (item) selectItem(item)
    } else if (e.key === 'Escape') {
        e.preventDefault()
        closeDropdown()
    }
}

// Reset highlight when filter changes
watch(query, () => {
    highlightedIndex.value = 0
})

// ── Click-outside to close ────────────────────────────────────────────────────
function onDocClick(e: MouseEvent) {
    if (!rootRef.value) return
    if (!rootRef.value.contains(e.target as Node)) {
        closeDropdown()
    }
}
watch(open, (o) => {
    if (o) document.addEventListener('mousedown', onDocClick)
    else   document.removeEventListener('mousedown', onDocClick)
})
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocClick))

// ── Helpers for template ─────────────────────────────────────────────────────
function isHighlighted(item: LocationOption): boolean {
    const idx = flatItems.value.indexOf(item)
    return idx === highlightedIndex.value
}
</script>

<template>
    <div ref="rootRef" class="relative">
        <!-- Trigger button / selected chip -->
        <button
            v-if="!open"
            type="button"
            class="w-full flex items-center justify-between gap-2 border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 hover:border-gray-400 dark:hover:border-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
            @click="openDropdown"
        >
            <span v-if="selected" class="flex items-center gap-2 min-w-0">
                <BuildingOffice2Icon class="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0" />
                <span class="truncate">{{ selected.name }}</span>
                <span v-if="selected.city" class="text-gray-400 dark:text-slate-500 text-sm truncate">
                    · {{ selected.city }}
                </span>
            </span>
            <span v-else class="text-gray-400 dark:text-slate-500">{{ placeholder }}</span>
            <div class="flex items-center gap-1 shrink-0">
                <button
                    v-if="selected"
                    type="button"
                    class="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-slate-600 text-gray-400 dark:text-slate-500"
                    aria-label="Clear selection"
                    @click.stop="clearSelection"
                >
                    <XMarkIcon class="w-3.5 h-3.5" />
                </button>
                <ChevronDownIcon class="w-4 h-4 text-gray-400 dark:text-slate-500" />
            </div>
        </button>

        <!-- Open: search input + dropdown -->
        <div v-else class="relative">
            <input
                ref="inputRef"
                v-model="query"
                type="text"
                :placeholder="placeholder"
                class="w-full border border-blue-400 dark:border-blue-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 pr-8"
                @keydown="onKeydown"
            />
            <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 p-0.5 rounded text-gray-400 dark:text-slate-500 hover:bg-gray-100 dark:hover:bg-slate-600"
                aria-label="Close dropdown"
                @click="closeDropdown"
            >
                <XMarkIcon class="w-4 h-4" />
            </button>

            <!-- Dropdown panel -->
            <div
                class="absolute left-0 right-0 z-50 mt-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg max-h-72 overflow-y-auto"
            >
                <!-- No results -->
                <p v-if="groups.length === 0" class="px-3 py-3 text-sm text-gray-400 dark:text-slate-500 text-center">
                    No matching locations.
                </p>

                <!-- Groups -->
                <template v-else>
                    <div v-for="group in groups" :key="group.label">
                        <div
                            :class="[
                                'px-3 py-1.5 text-xs font-semibold uppercase tracking-wider',
                                group.isPace
                                    ? 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300'
                                    : 'bg-gray-50 dark:bg-slate-700/50 text-gray-500 dark:text-slate-400',
                            ]"
                        >
                            {{ group.label }}
                        </div>
                        <ul>
                            <li v-for="item in group.items" :key="item.id">
                                <button
                                    type="button"
                                    :class="[
                                        'w-full text-left pl-8 pr-3 py-2 text-sm flex items-center gap-2',
                                        isHighlighted(item)
                                            ? 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300'
                                            : 'text-gray-900 dark:text-slate-100 hover:bg-gray-50 dark:hover:bg-slate-700',
                                    ]"
                                    @click="selectItem(item)"
                                    @mouseenter="highlightedIndex = flatItems.indexOf(item)"
                                >
                                    <span class="text-gray-300 dark:text-slate-600 shrink-0">•</span>
                                    <span class="truncate flex-1">{{ item.name }}</span>
                                    <span v-if="item.city" class="text-gray-400 dark:text-slate-500 text-sm shrink-0">
                                        {{ item.city }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
