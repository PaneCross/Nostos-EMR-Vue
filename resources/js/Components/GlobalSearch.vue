<script setup lang="ts">
// ─── GlobalSearch.vue ─────────────────────────────────────────────────────────
// Cmd+K / Ctrl+K participant search modal.
// Debounced search against /participants/search, keyboard-navigable results.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, watch, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

interface SearchResult {
    id: number
    mrn: string
    name: string
    dob: string
    age: number
    enrollment_status: string
    flags: string[]
}

const STATUS_COLORS: Record<string, string> = {
    enrolled:    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    disenrolled: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    deceased:    'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400',
    pending:     'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
    intake:      'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    referred:    'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
}

const FLAG_COLORS: Record<string, string> = {
    wheelchair: 'bg-blue-100 text-blue-700',
    stretcher:  'bg-indigo-100 text-indigo-700',
    oxygen:     'bg-cyan-100 text-cyan-700',
    behavioral: 'bg-orange-100 text-orange-700',
    fall_risk:  'bg-red-100 text-red-700',
    dnr:        'bg-red-200 text-red-800',
    hospice:    'bg-purple-100 text-purple-700',
}

function flagColor(flag: string): string {
    return FLAG_COLORS[flag] ?? 'bg-gray-100 text-gray-600'
}

function flagLabel(flag: string): string {
    return flag.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
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
            const res = await axios.get<SearchResult[]>('/participants/search', {
                params: { q },
                signal: controller.signal,
            })
            results.value = res.data
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
        if (sel) goTo(sel.id)
    }
}

function goTo(id: number) {
    emit('close')
    router.visit(`/participants/${id}`)
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
                    <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded">
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
                    No participants found matching
                    <span class="font-medium text-slate-700 dark:text-slate-200">"{{ query }}"</span>
                </div>

                <!-- Results -->
                <ul v-if="results.length > 0" class="py-1 max-h-80 overflow-y-auto" role="listbox">
                    <li
                        v-for="(r, idx) in results"
                        :key="r.id"
                        role="option"
                        :aria-selected="idx === activeIndex"
                        :class="[
                            'flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors',
                            idx === activeIndex
                                ? 'bg-blue-50 dark:bg-blue-900/20'
                                : 'hover:bg-slate-50 dark:hover:bg-slate-700/50',
                        ]"
                        @mouseenter="activeIndex = idx"
                        @click="goTo(r.id)"
                    >
                        <!-- Avatar -->
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold shrink-0">
                            {{ r.name.split(' ').map((p: string) => p[0]).slice(0, 2).join('') }}
                        </div>
                        <!-- Details -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ r.name }}</span>
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ r.mrn }}</span>
                                <span :class="['inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium', STATUS_COLORS[r.enrollment_status] ?? 'bg-gray-100 text-gray-600']">
                                    {{ r.enrollment_status }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 mt-0.5">
                                <span class="text-xs text-slate-500 dark:text-slate-400">DOB {{ r.dob }} - Age {{ r.age }}</span>
                                <div v-if="r.flags.length > 0" class="flex gap-1 flex-wrap">
                                    <span
                                        v-for="flag in r.flags.slice(0, 3)"
                                        :key="flag"
                                        :class="['inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium', flagColor(flag)]"
                                    >
                                        {{ flagLabel(flag) }}
                                    </span>
                                    <span v-if="r.flags.length > 3" class="text-[10px] text-slate-500">+{{ r.flags.length - 3 }}</span>
                                </div>
                            </div>
                        </div>
                        <!-- Arrow -->
                        <svg v-if="idx === activeIndex" class="w-4 h-4 text-blue-500 shrink-0 mt-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </li>
                </ul>

                <!-- Keyboard hints -->
                <div v-if="query.trim().length < 2" class="px-4 py-3 flex items-center gap-4 text-[11px] text-slate-400 dark:text-slate-500 border-t border-slate-100 dark:border-slate-700">
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">↑↓</kbd> navigate</span>
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">↵</kbd> open</span>
                    <span><kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded text-slate-500 dark:text-slate-400">ESC</kbd> close</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
