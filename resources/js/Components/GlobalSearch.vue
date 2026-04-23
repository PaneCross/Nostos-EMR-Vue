<script setup lang="ts">
// ─── GlobalSearch.vue ─────────────────────────────────────────────────────────
// Cmd+K / Ctrl+K global search modal. Phase 14.7 extended from
// participant-only to 6 entity types (participants, referrals, appointments,
// grievances, orders, sdrs). Hits /search endpoint; flattens grouped
// response; kind badges per result.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, watch, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

interface SearchResult {
    id: number
    kind: string       // participant | referral | appointment | grievance | order | sdr
    label: string
    sublabel: string
    href: string
}

const KIND_COLORS: Record<string, string> = {
    participant: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    referral:    'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
    appointment: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    grievance:   'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    order:       'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
    sdr:         'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
}

function kindColor(kind: string): string {
    return KIND_COLORS[kind] ?? 'bg-gray-100 text-gray-600'
}

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const query       = ref('')
const results     = ref<SearchResult[]>([])
const loading     = ref(false)
const error       = ref<string | null>(null)
const activeIndex = ref(0)
const inputRef    = ref<HTMLInputElement | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | null = null
let abortController: AbortController | null = null

watch(() => props.open, (val) => {
    if (val) {
        query.value = ''
        results.value = []
        activeIndex.value = 0
        error.value = null
        nextTick(() => inputRef.value?.focus())
    }
})

watch(query, (q) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    if (abortController) abortController.abort()

    if (q.trim().length < 2) {
        results.value = []
        loading.value = false
        return
    }

    loading.value = true
    error.value = null

    debounceTimer = setTimeout(async () => {
        const controller = new AbortController()
        abortController = controller
        try {
            const res = await axios.get<{ groups: Record<string, SearchResult[]> }>('/search', {
                params: { q },
                signal: controller.signal,
            })
            // Flatten groups into a single ordered list: participants first,
            // then the rest in insertion order.
            const groups = res.data.groups ?? {}
            const order = ['participants', 'referrals', 'appointments', 'grievances', 'orders', 'sdrs']
            results.value = order.flatMap(g => groups[g] ?? [])
            activeIndex.value = 0
        } catch (err: unknown) {
            if (axios.isCancel(err)) return
            error.value = 'Search failed. Please try again.'
            results.value = []
        } finally {
            loading.value = false
        }
    }, 280)
})

function handleKeyDown(e: KeyboardEvent) {
    if (e.key === 'Escape') { emit('close'); return }
    if (results.value.length === 0) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activeIndex.value = Math.max(activeIndex.value - 1, 0)
    } else if (e.key === 'Enter') {
        e.preventDefault()
        const sel = results.value[activeIndex.value]
        if (sel) goTo(sel)
    }
}

function goTo(r: SearchResult) {
    emit('close')
    router.visit(r.href)
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[70] flex items-start justify-center pt-24 px-4"
            @click.self="emit('close')"
        >
            <!-- Dim overlay -->
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm pointer-events-none" aria-hidden="true" />

            <!-- Panel -->
            <div class="relative w-full max-w-xl bg-white dark:bg-slate-800 rounded-xl shadow-2xl ring-1 ring-black/10 dark:ring-white/10 flex flex-col overflow-hidden">
                <!-- Input row -->
                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-200 dark:border-slate-700">
                    <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" />
                    </svg>
                    <input
                        ref="inputRef"
                        v-model="query"
                        type="text"
                        placeholder="Search by name, MRN, or date of birth (YYYY-MM-DD)..."
                        class="flex-1 bg-transparent text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none"
                        aria-label="Search participants"
                        autocomplete="off"
                        @keydown="handleKeyDown"
                    />
                    <svg v-if="loading" class="w-4 h-4 text-slate-400 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 text-xs font-medium text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded">
                        ESC
                    </kbd>
                </div>

                <!-- Error -->
                <div v-if="error" class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>

                <!-- Empty state -->
                <div
                    v-else-if="query.trim().length >= 2 && !loading && results.length === 0"
                    class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400"
                >
                    No results found matching
                    <span class="font-medium text-slate-700 dark:text-slate-200">"{{ query }}"</span>
                </div>

                <!-- Results -->
                <ul v-if="results.length > 0" class="py-1 max-h-96 overflow-y-auto" role="listbox">
                    <li
                        v-for="(r, idx) in results"
                        :key="`${r.kind}-${r.id}`"
                        role="option"
                        :aria-selected="idx === activeIndex"
                        :class="[
                            'flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors',
                            idx === activeIndex
                                ? 'bg-blue-50 dark:bg-blue-900/20'
                                : 'hover:bg-slate-50 dark:hover:bg-slate-700/50',
                        ]"
                        @mouseenter="activeIndex = idx"
                        @click="goTo(r)"
                    >
                        <!-- Kind badge -->
                        <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-medium shrink-0', kindColor(r.kind)]">
                            {{ r.kind }}
                        </span>
                        <!-- Details -->
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ r.label }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ r.sublabel }}</div>
                        </div>
                        <!-- Arrow -->
                        <svg v-if="idx === activeIndex" class="w-4 h-4 text-blue-500 shrink-0 mt-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </li>
                </ul>

                <!-- Keyboard hints -->
                <div v-if="query.trim().length < 2" class="px-4 py-3 flex items-center gap-4 text-xs text-slate-400 dark:text-slate-500 border-t border-slate-100 dark:border-slate-700">
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">↑↓</kbd> navigate</span>
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">↵</kbd> open</span>
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">ESC</kbd> close</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
