<script setup lang="ts">
// ─── ChartTab.vue ─────────────────────────────────────────────────────────────
// Clinical notes chart for the participant. Lists notes with type/dept filter,
// expandable note view, sign action for draft notes, addendum on signed notes.
// Notes are lazy-loaded via axios on first tab activation.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { DocumentTextIcon, PlusIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

interface Note {
  id: number; note_type: string; status: 'draft' | 'signed' | 'amended'
  department: string; visit_type: string; visit_date: string
  subjective: string | null; objective: string | null
  assessment: string | null; plan: string | null
  is_late_entry: boolean; signed_at: string | null
  authored_by_user_id: number
  author: { id: number; first_name: string; last_name: string } | null
  site: { id: number; name: string } | null
  created_at: string
}

interface Participant { id: number; first_name: string; last_name: string }

const props = defineProps<{
  participant: Participant
  notes?: Note[]
  noteTemplates?: Record<string, unknown>
}>()

const NOTE_TYPE_LABELS: Record<string, string> = {
  soap: 'Primary Care SOAP', progress_nursing: 'Nursing Progress',
  therapy_pt: 'PT Therapy', therapy_ot: 'OT Therapy', therapy_st: 'ST Therapy',
  social_work: 'Social Work', behavioral_health: 'Behavioral Health',
  dietary: 'Dietary / Nutrition', home_visit: 'Home Visit', telehealth: 'Telehealth',
  idt_summary: 'IDT Summary', incident: 'Incident Report', addendum: 'Addendum',
}

const STATUS_COLORS: Record<string, string> = {
  draft:   'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  signed:  'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  amended: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
}

const page = usePage()
const auth = computed(() => (page.props as Record<string, unknown>).auth as { user: { id: number; department: string } } | null)

const notes = ref<Note[]>(props.notes ?? [])
const loading = ref(false)
const filterType = ref('')
const expandedIds = ref<Set<number>>(new Set())
const showAddForm = ref(false)
const signing = ref<number | null>(null)

const filteredNotes = computed(() =>
  filterType.value ? notes.value.filter(n => n.note_type === filterType.value) : notes.value
)

function toggleExpand(id: number) {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function signNote(noteId: number) {
  signing.value = noteId
  try {
    await axios.post(`/participants/${props.participant.id}/notes/${noteId}/sign`)
    const idx = notes.value.findIndex(n => n.id === noteId)
    if (idx !== -1) notes.value[idx].status = 'signed'
  } catch {
    alert('Failed to sign note. You may not have permission.')
  } finally {
    signing.value = null
  }
}

// ── New note form ──────────────────────────────────────────────────────────────

const blankNote = () => ({
  note_type: 'soap', visit_type: 'in_center', visit_date: new Date().toISOString().slice(0, 10),
  subjective: '', objective: '', assessment: '', plan: '', department: auth.value?.user.department ?? '',
})
const noteForm = ref(blankNote())
const savingNote = ref(false)
const noteError = ref('')

async function submitNote() {
  savingNote.value = true
  noteError.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/notes`, noteForm.value)
    notes.value.unshift(res.data)
    showAddForm.value = false
    noteForm.value = blankNote()
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    noteError.value = e.response?.data?.message ?? 'Failed to save note.'
  } finally {
    savingNote.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header row -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div class="flex items-center gap-2">
        <DocumentTextIcon class="w-5 h-5 text-gray-400 dark:text-slate-500" />
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Clinical Notes</h2>
        <span class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded">{{ filteredNotes.length }}</span>
      </div>
      <div class="flex items-center gap-2">
        <select
          v-model="filterType"
          class="text-xs border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
        >
          <option value="">All types</option>
          <option v-for="(label, key) in NOTE_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showAddForm = !showAddForm"
        >
          <PlusIcon class="w-3 h-3" />
          New Note
        </button>
      </div>
    </div>

    <!-- Add note form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">New Clinical Note</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Note Type</label>
          <select v-model="noteForm.note_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option v-for="(label, key) in NOTE_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Visit Type</label>
          <select v-model="noteForm.visit_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="in_center">In Center</option>
            <option value="home_visit">Home Visit</option>
            <option value="phone">Phone</option>
            <option value="telehealth">Telehealth</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Visit Date</label>
        <input type="date" v-model="noteForm.visit_date" class="text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
      </div>
      <div v-for="field in ['subjective', 'objective', 'assessment', 'plan']" :key="field" class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1 capitalize">{{ field }}</label>
        <textarea
          v-model="(noteForm as Record<string, string>)[field]"
          rows="2"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
        />
      </div>
      <p v-if="noteError" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ noteError }}</p>
      <div class="flex gap-2">
        <button
          :disabled="savingNote"
          class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          @click="submitNote"
        >
          {{ savingNote ? 'Saving...' : 'Save Draft' }}
        </button>
        <button
          class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          @click="showAddForm = false"
        >
          Cancel
        </button>
      </div>
    </div>

    <!-- Notes list -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">Loading notes...</div>
    <div v-else-if="filteredNotes.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No notes found.</div>
    <div v-else class="space-y-2">
      <div
        v-for="note in filteredNotes"
        :key="note.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden"
      >
        <!-- Note header row (always visible) -->
        <button
          class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
          @click="toggleExpand(note.id)"
        >
          <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ NOTE_TYPE_LABELS[note.note_type] ?? note.note_type }}</span>
          <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_COLORS[note.status] ?? '']">{{ note.status }}</span>
          <span v-if="note.is_late_entry" class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-2 py-0.5 rounded">Late Entry</span>
          <span class="ml-auto text-xs text-gray-500 dark:text-slate-400">{{ fmtDate(note.visit_date) }}</span>
          <span class="text-xs text-gray-400 dark:text-slate-500">
            {{ note.author ? `${note.author.first_name} ${note.author.last_name}` : '-' }}
          </span>
          <ChevronDownIcon :class="['w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform', expandedIds.has(note.id) ? 'rotate-180' : '']" />
        </button>

        <!-- Expanded note content -->
        <div v-if="expandedIds.has(note.id)" class="border-t border-gray-100 dark:border-slate-700 px-4 py-3 space-y-2">
          <div v-for="section in ['subjective', 'objective', 'assessment', 'plan']" :key="section">
            <template v-if="(note as Record<string, unknown>)[section]">
              <dt class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase">{{ section }}</dt>
              <dd class="text-sm text-gray-700 dark:text-slate-300 mt-0.5 whitespace-pre-line">{{ (note as Record<string, unknown>)[section] }}</dd>
            </template>
          </div>

          <div v-if="note.site" class="text-xs text-gray-400 dark:text-slate-500">
            Site: {{ note.site.name }}
          </div>

          <!-- Sign button for own draft notes -->
          <div class="flex gap-2 pt-1">
            <button
              v-if="note.status === 'draft' && note.authored_by_user_id === auth?.user.id"
              :disabled="signing === note.id"
              class="text-xs px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50 transition-colors"
              @click="signNote(note.id)"
            >
              {{ signing === note.id ? 'Signing...' : 'Sign Note' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
