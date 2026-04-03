<script setup lang="ts">
// ─── Tabs/ChartTab.vue ────────────────────────────────────────────────────────
// Clinical notes chart. Lazy-loads from GET /participants/{id}/notes. Supports
// filtering by department, note type, and date range. Add Note modal with
// template selection, rich-text body, and co-signer. Notes can be signed or
// addended; multi-site participants show a site badge on each note.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { PlusIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

interface Note {
  id: number
  note_type: string
  department: string
  content: string
  status: string
  signed_at: string | null
  signed_by_name: string | null
  created_at: string
  author_name: string | null
  cosigner_name: string | null
  addendums: { id: number; content: string; created_at: string; author_name: string | null }[]
  site_name: string | null
}

const props = defineProps<{
  participantId: number
  noteTemplates: Record<string, { label: string; departments: string[] }>
  hasMultipleSites: boolean
}>()

const notes    = ref<Note[]>([])
const loading  = ref(true)
const error    = ref<string | null>(null)

// Filters
const filterDept = ref('')
const filterType = ref('')
const filterFrom = ref('')
const filterTo   = ref('')

// Add Note modal
const showModal    = ref(false)
const modalLoading = ref(false)
const modalError   = ref<string | null>(null)
const form = ref({ note_type: '', content: '', cosigner_user_id: '' })

// Expanded note IDs
const expanded = ref<Set<number>>(new Set())

function toggleExpand(id: number) {
  if (expanded.value.has(id)) expanded.value.delete(id)
  else expanded.value.add(id)
}

async function loadNotes() {
  loading.value = true
  error.value = null
  try {
    const params: Record<string, string> = {}
    if (filterDept.value) params.department = filterDept.value
    if (filterType.value) params.note_type = filterType.value
    if (filterFrom.value) params.from = filterFrom.value
    if (filterTo.value) params.to = filterTo.value
    const r = await axios.get(`/participants/${props.participantId}/notes`, { params })
    notes.value = r.data.data ?? r.data
  } catch {
    error.value = 'Failed to load notes. Please refresh.'
  } finally {
    loading.value = false
  }
}

onMounted(loadNotes)

const templateOptions = computed(() =>
  Object.entries(props.noteTemplates).map(([key, val]) => ({ key, label: val.label }))
)

const DEPT_LABELS: Record<string, string> = {
  primary_care: 'Primary Care', nursing: 'Nursing', therapies: 'Therapies',
  social_work: 'Social Work', nutrition: 'Nutrition', pharmacy: 'Pharmacy',
  transportation: 'Transportation', activities: 'Activities', home_care: 'Home Care',
  day_health: 'Day Health', enrollment: 'Enrollment', qa_compliance: 'QA/Compliance',
  finance: 'Finance', it_admin: 'IT Admin',
}

const STATUS_CLASSES: Record<string, string> = {
  draft:     'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
  signed:    'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  addended:  'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  cosigned:  'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300',
}

function fmtDatetime(val: string): string {
  return new Date(val).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  })
}

async function submitNote() {
  if (!form.value.note_type || !form.value.content.trim()) {
    modalError.value = 'Note type and content are required.'
    return
  }
  modalLoading.value = true
  modalError.value = null
  router.post(`/participants/${props.participantId}/notes`, {
    note_type: form.value.note_type,
    content: form.value.content,
    cosigner_user_id: form.value.cosigner_user_id || null,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      showModal.value = false
      form.value = { note_type: '', content: '', cosigner_user_id: '' }
      loadNotes()
    },
    onError: (e: Record<string, string>) => {
      modalError.value = e.content ?? e.note_type ?? 'Failed to save note.'
    },
    onFinish: () => { modalLoading.value = false },
  })
}

async function signNote(noteId: number) {
  router.patch(`/participants/${props.participantId}/notes/${noteId}/sign`, {}, {
    preserveScroll: true,
    onSuccess: loadNotes,
  })
}
</script>

<template>
  <div>
    <!-- Header row -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Clinical Notes ({{ notes.length }})</h3>
      <button
        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1.5"
        aria-label="Add new note"
        @click="showModal = true"
      >
        <PlusIcon class="w-4 h-4" />
        Add Note
      </button>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-2 mb-4">
      <select v-model="filterDept" class="text-xs border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300" @change="loadNotes">
        <option value="">All Departments</option>
        <option v-for="(label, key) in DEPT_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model="filterType" class="text-xs border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300" @change="loadNotes">
        <option value="">All Types</option>
        <option v-for="t in templateOptions" :key="t.key" :value="t.key">{{ t.label }}</option>
      </select>
      <input v-model="filterFrom" type="date" class="text-xs border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300" @change="loadNotes" />
      <input v-model="filterTo" type="date" class="text-xs border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300" @change="loadNotes" />
    </div>

    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">Loading...</div>
    <div v-else-if="error" class="py-8 text-center text-red-500 text-sm">{{ error }}</div>
    <p v-else-if="notes.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No notes on file.</p>

    <!-- Notes list -->
    <div v-else class="space-y-3">
      <div
        v-for="note in notes"
        :key="note.id"
        class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden"
      >
        <!-- Note header -->
        <div
          class="flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-slate-700/50 cursor-pointer"
          @click="toggleExpand(note.id)"
        >
          <div class="flex items-center gap-3 flex-wrap">
            <span class="text-xs font-semibold text-gray-800 dark:text-slate-200">
              {{ noteTemplates[note.note_type]?.label ?? note.note_type }}
            </span>
            <span class="text-xs text-gray-500 dark:text-slate-400">{{ DEPT_LABELS[note.department] ?? note.department }}</span>
            <span :class="`text-xs px-1.5 py-0.5 rounded-full font-medium ${STATUS_CLASSES[note.status] ?? 'bg-gray-100 text-gray-600'}`">{{ note.status }}</span>
            <span v-if="hasMultipleSites && note.site_name" class="text-xs px-1.5 py-0.5 rounded bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300">{{ note.site_name }}</span>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400 dark:text-slate-500">{{ fmtDatetime(note.created_at) }}</span>
            <ChevronDownIcon v-if="!expanded.has(note.id)" class="w-4 h-4 text-gray-400" />
            <ChevronUpIcon v-else class="w-4 h-4 text-gray-400" />
          </div>
        </div>

        <!-- Note body (expanded) -->
        <div v-if="expanded.has(note.id)" class="px-4 py-3 bg-white dark:bg-slate-800">
          <p class="text-xs text-gray-500 dark:text-slate-400 mb-2">
            By {{ note.author_name ?? 'Unknown' }}
            <span v-if="note.cosigner_name"> | Co-signed: {{ note.cosigner_name }}</span>
            <span v-if="note.signed_at"> | Signed: {{ fmtDatetime(note.signed_at) }}</span>
          </p>
          <p class="text-sm text-gray-800 dark:text-slate-200 whitespace-pre-wrap">{{ note.content }}</p>

          <!-- Addendums -->
          <div v-if="note.addendums.length > 0" class="mt-3 space-y-2">
            <p class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide">Addendums</p>
            <div v-for="add in note.addendums" :key="add.id" class="border-l-2 border-blue-300 dark:border-blue-700 pl-3">
              <p class="text-xs text-gray-500 dark:text-slate-400">{{ add.author_name ?? 'Unknown' }} - {{ fmtDatetime(add.created_at) }}</p>
              <p class="text-sm text-gray-800 dark:text-slate-200 whitespace-pre-wrap">{{ add.content }}</p>
            </div>
          </div>

          <!-- Sign button for draft notes -->
          <div v-if="note.status === 'draft'" class="mt-3">
            <button
              class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors"
              aria-label="Sign this note"
              @click.stop="signNote(note.id)"
            >Sign Note</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Note modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">New Clinical Note</h3>
        <p v-if="modalError" class="text-sm text-red-600 dark:text-red-400 mb-3">{{ modalError }}</p>
        <div class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Note Type</label>
            <select v-model="form.note_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
              <option value="">Select a type...</option>
              <option v-for="t in templateOptions" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Content</label>
            <textarea
              v-model="form.content"
              rows="8"
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-y"
              placeholder="Enter clinical note..."
            />
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button
            class="text-sm px-4 py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-200 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors"
            @click="showModal = false"
          >Cancel</button>
          <button
            :disabled="modalLoading"
            class="text-sm px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors"
            @click="submitNote"
          >{{ modalLoading ? 'Saving...' : 'Save Note' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
