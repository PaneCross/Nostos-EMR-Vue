<script setup lang="ts">
// ─── Clinical/Notes ─────────────────────────────────────────────────────────
// Cross-participant browser of clinical notes (progress notes, IDT notes,
// SW notes, etc.). Filterable by department, note type, and signing status.
//
// Audience: All clinical roles; QA Compliance for unsigned-note review.
//
// Notable rules:
//   - Notes follow a draft -> signed lifecycle. Once signed, content is
//     immutable; addenda must be appended (CMS audit trail integrity).
//   - HCC RAF (Hierarchical Condition Category Risk Adjustment Factor)
//     recalculation fires automatically when a problem-linked note is signed.
// ────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { DocumentTextIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Note {
    id: number
    note_type: string
    status: 'draft' | 'signed' | 'addendum'
    visit_date: string | null
    visit_type: string | null
    department: string | null
    author: { id: number; first_name: string; last_name: string; department: string } | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
}

interface Paginator {
    data: Note[]
    current_page: number
    last_page: number
    total: number
    per_page: number
}

const props = defineProps<{
    notes: Paginator
    filters: { department?: string; note_type?: string; status?: string }
    noteTypes: string[]
}>()

// ── Filter state ───────────────────────────────────────────────────────────────

const department = ref(props.filters.department ?? '')
const noteType = ref(props.filters.note_type ?? '')
const status = ref(props.filters.status ?? '')

function applyFilters() {
    router.get(
        '/clinical/notes',
        {
            department: department.value,
            note_type: noteType.value,
            status: status.value,
        },
        { preserveState: true, replace: true },
    )
}

function onDeptChange(e: Event) {
    department.value = (e.target as HTMLSelectElement).value
    applyFilters()
}

function onTypeChange(e: Event) {
    noteType.value = (e.target as HTMLSelectElement).value
    applyFilters()
}

function onStatusChange(e: Event) {
    status.value = (e.target as HTMLSelectElement).value
    applyFilters()
}

// ── Pagination ────────────────────────────────────────────────────────────────

function goToPage(page: number) {
    router.visit(`/clinical/notes?page=${page}`, { preserveState: true })
}

// ── Display helpers ────────────────────────────────────────────────────────────

const DEPARTMENTS = [
    'primary_care',
    'therapies',
    'social_work',
    'behavioral_health',
    'dietary',
    'activities',
    'home_care',
    'transportation',
    'pharmacy',
    'idt',
    'enrollment',
    'finance',
    'qa_compliance',
    'it_admin',
]

function fmtDept(d: string): string {
    return d.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function fmtDate(val: string | null): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function fmtNoteType(t: string): string {
    return t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

// Status badge classes
const STATUS_BADGE: Record<string, string> = {
    draft:    'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
    signed:   'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    addendum: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
}

function statusBadge(s: string): string {
    return STATUS_BADGE[s] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
}
</script>

<template>
    <Head title="Clinical Notes" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <DocumentTextIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Clinical Notes
                </h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header ── -->
            <div class="flex items-center gap-3 mb-5">
                <span class="text-sm text-gray-500 dark:text-slate-400">
                    {{ notes.total.toLocaleString() }}
                    note{{ notes.total !== 1 ? 's' : '' }}
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300">
                    {{ notes.total.toLocaleString() }} total
                </span>
            </div>

            <!-- ── Filter bar ── -->
            <div class="flex flex-wrap gap-2 mb-4">
                <!-- Department filter -->
                <select name="select"
                    :value="department"
                    aria-label="Filter by department"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onDeptChange"
                >
                    <option value="">All Departments</option>
                    <option v-for="d in DEPARTMENTS" :key="d" :value="d">
                        {{ fmtDept(d) }}
                    </option>
                </select>

                <!-- Note type filter -->
                <select name="select"
                    :value="noteType"
                    aria-label="Filter by note type"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onTypeChange"
                >
                    <option value="">All Note Types</option>
                    <option v-for="t in noteTypes" :key="t" :value="t">
                        {{ fmtNoteType(t) }}
                    </option>
                </select>

                <!-- Status filter -->
                <select name="select"
                    :value="status"
                    aria-label="Filter by status"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onStatusChange"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="signed">Signed</option>
                    <option value="addendum">Addendum</option>
                </select>
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700" aria-label="Clinical notes">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th
                                v-for="col in ['Participant', 'Note Type', 'Status', 'Visit Date', 'Author', 'Department']"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-if="notes.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                No notes found.
                            </td>
                        </tr>

                        <tr
                            v-for="note in notes.data"
                            :key="note.id"
                            class="bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
                            tabindex="0"
                            :aria-label="note.participant ? `Open chart for ${note.participant.last_name}, ${note.participant.first_name}` : 'Open participant chart'"
                            @click="note.participant && router.visit(`/participants/${note.participant.id}?tab=chart`)"
                            @keydown.enter="note.participant && router.visit(`/participants/${note.participant.id}?tab=chart`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <template v-if="note.participant">
                                    <div class="font-medium text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                        {{ note.participant.last_name }}, {{ note.participant.first_name }}
                                    </div>
                                    <div class="font-mono text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                        {{ note.participant.mrn }}
                                    </div>
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500 text-sm">-</span>
                            </td>

                            <!-- Note type -->
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-slate-300">
                                {{ fmtNoteType(note.note_type) }}
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize', statusBadge(note.status)]"
                                >
                                    {{ note.status }}
                                </span>
                            </td>

                            <!-- Visit date -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                {{ fmtDate(note.visit_date) }}
                            </td>

                            <!-- Author -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                <template v-if="note.author">
                                    {{ note.author.last_name }}, {{ note.author.first_name }}
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>

                            <!-- Department -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                {{ note.department ? fmtDept(note.department) : '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ── -->
            <div
                v-if="notes.last_page > 1"
                class="flex items-center justify-between mt-4 text-sm"
            >
                <span class="text-gray-500 dark:text-slate-400">
                    Page {{ notes.current_page }} of {{ notes.last_page }}
                </span>
                <div class="flex items-center gap-1" role="navigation" aria-label="Pagination">
                    <button
                        :disabled="notes.current_page <= 1"
                        :class="[
                            'inline-flex items-center gap-1 px-3 py-1.5 rounded-md border text-xs font-medium transition-colors',
                            notes.current_page > 1
                                ? 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/50'
                                : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed',
                        ]"
                        @click="notes.current_page > 1 && goToPage(notes.current_page - 1)"
                    >
                        <ChevronLeftIcon class="w-3.5 h-3.5" aria-hidden="true" />
                        Prev
                    </button>
                    <button
                        :disabled="notes.current_page >= notes.last_page"
                        :class="[
                            'inline-flex items-center gap-1 px-3 py-1.5 rounded-md border text-xs font-medium transition-colors',
                            notes.current_page < notes.last_page
                                ? 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/50'
                                : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed',
                        ]"
                        @click="notes.current_page < notes.last_page && goToPage(notes.current_page + 1)"
                    >
                        Next
                        <ChevronRightIcon class="w-3.5 h-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
