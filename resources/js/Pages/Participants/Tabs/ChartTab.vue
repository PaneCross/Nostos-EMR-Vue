<script setup lang="ts">
// ─── ChartTab.vue ─────────────────────────────────────────────────────────────
// Clinical notes chart for the participant.
// Notes are lazy-loaded via axios on first mount (not passed in Inertia props).
// Supports SOAP and all other note types. Draft notes can be signed.
// Signed notes can have addendums. Status chips filter All/Draft/Signed.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { DocumentTextIcon, PlusIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

interface NoteContent { notes?: string }

interface Note {
  id: number; note_type: string; status: 'draft' | 'signed' | 'amended'
  department: string; visit_type: string; visit_date: string
  subjective: string | null; objective: string | null
  assessment: string | null; plan: string | null
  content: NoteContent | null
  is_late_entry: boolean; signed_at: string | null
  authored_by_user_id: number
  author: { id: number; first_name: string; last_name: string } | null
  site: { id: number; name: string } | null
  created_at: string
}

interface Participant { id: number; first_name: string; last_name: string }

const props = defineProps<{
  participant: Participant
  noteTemplates?: Record<string, { label: string; departments?: string[] }>
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
  amended: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
}

const page = usePage()
const auth = computed(() => (page.props as Record<string, unknown>).auth as { user: { id: number; department: string } } | null)

// ── Notes state (lazy-loaded) ─────────────────────────────────────────────────
const notes    = ref<Note[]>([])
const loading  = ref(false)
const loadErr  = ref('')

onMounted(async () => {
  loading.value = true
  try {
    const res = await axios.get(`/participants/${props.participant.id}/notes`, { params: { per_page: 50 } })
    notes.value = res.data.data ?? res.data
  } catch {
    loadErr.value = 'Failed to load notes. Please refresh.'
  } finally {
    loading.value = false
  }
})

// ── Filters ───────────────────────────────────────────────────────────────────
const statusFilter = ref<'all' | 'draft' | 'signed'>('all')
const typeFilter   = ref('')

const filteredNotes = computed(() => {
  let result = notes.value
  if (statusFilter.value !== 'all') result = result.filter(n => n.status === statusFilter.value)
  if (typeFilter.value)             result = result.filter(n => n.note_type === typeFilter.value)
  return result
})

// ── Expand / collapse ─────────────────────────────────────────────────────────
const expandedIds = ref<Set<number>>(new Set())
function toggleExpand(id: number) {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
  // Force reactivity on Set mutation
  expandedIds.value = new Set(expandedIds.value)
}

// ── Note preview (collapsed summary) ─────────────────────────────────────────
function notePreview(note: Note): string {
  const raw = note.note_type === 'soap'
    ? [note.subjective, note.objective, note.assessment, note.plan].filter(Boolean).join(' · ')
    : (note.content?.notes ?? '')
  return raw.length > 160 ? raw.slice(0, 160) + '…' : raw
}

// ── Date formatting ───────────────────────────────────────────────────────────
function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

// ── Sign note ─────────────────────────────────────────────────────────────────
const signingId = ref<number | null>(null)
async function signNote(noteId: number) {
  signingId.value = noteId
  try {
    const { data } = await axios.post(`/participants/${props.participant.id}/notes/${noteId}/sign`)
    const idx = notes.value.findIndex(n => n.id === noteId)
    if (idx !== -1) notes.value[idx] = { ...notes.value[idx], ...data }
  } catch {
    // sign failed — leave note open
  } finally {
    signingId.value = null
  }
}

// ── Phase I3 addendum compose modal ───────────────────────────────────────────
const addendumParentId = ref<number | null>(null)
const addendumComposeOpen = ref(false)
const addendumComposeParent = ref<number | null>(null)
const addendumComposeText = ref('')
const addendumSubmitting = ref(false)

function openAddendumCompose(parentNoteId: number) {
  addendumComposeParent.value = parentNoteId
  addendumComposeText.value = ''
  addendumComposeOpen.value = true
}
function closeAddendumCompose() {
  addendumComposeOpen.value = false
  addendumComposeParent.value = null
}
async function createAddendum() {
  if (!addendumComposeParent.value) return
  if (addendumComposeText.value.trim().length < 3) return
  addendumSubmitting.value = true
  addendumParentId.value = addendumComposeParent.value
  try {
    const { data } = await axios.post(
      `/participants/${props.participant.id}/notes/${addendumComposeParent.value}/addendum`,
      {
        note_type:  'addendum',
        visit_type: 'in_center',
        visit_date: new Date().toISOString().slice(0, 10),
        department: auth.value?.user.department ?? '',
        content:    { notes: addendumComposeText.value.trim() },
      }
    )
    notes.value.unshift(data)
    closeAddendumCompose()
  } catch {
    // addendum failed; no-op
  } finally {
    addendumParentId.value = null
    addendumSubmitting.value = false
  }
}

// ── Phase I3 note-template picker + problem linkage ──────────────────────────
interface NoteTemplateMeta {
  id: number
  name: string
  note_type: string
  department: string | null
  is_system: boolean
}
interface ProblemMeta {
  id: number
  icd10_code: string | null
  icd10_description: string | null
  status: string
}

const templates = ref<NoteTemplateMeta[]>([])
const problems = ref<ProblemMeta[]>([])
const templatesLoaded = ref(false)
const selectedTemplateId = ref<number | ''>('')
const primaryProblemId = ref<number | ''>('')
const secondaryProblemIds = ref<number[]>([])

async function loadTemplatesAndProblems() {
  if (templatesLoaded.value) return
  try {
    const [t, p] = await Promise.all([
      axios.get('/note-templates'),
      axios.get(`/participants/${props.participant.id}/problems`),
    ])
    templates.value = t.data?.templates ?? []
    // ProblemController::index groups by status: {active: [...], resolved: [...]}.
    // Be defensive in case it ever changes to a flat array.
    const rawP: any = p.data?.problems ?? p.data ?? {}
    if (Array.isArray(rawP)) {
      problems.value = rawP.filter((x: ProblemMeta) => x.status === 'active')
    } else {
      problems.value = (rawP.active ?? []) as ProblemMeta[]
    }
    templatesLoaded.value = true
  } catch {
    // fail silently — form still usable without template/problem helpers
  }
}

async function applyTemplate() {
  if (!selectedTemplateId.value) return
  try {
    const { data } = await axios.get(
      `/note-templates/${selectedTemplateId.value}/render/${props.participant.id}`
    )
    const rendered: string = data?.rendered ?? ''
    const tpl = templates.value.find(t => t.id === Number(selectedTemplateId.value))
    // Match note_type so downstream form behaves correctly
    if (tpl) {
      noteForm.value.note_type = tpl.note_type
    }
    // SOAP types split into 4 sections; non-SOAP gets single content_notes
    if (tpl?.note_type === 'soap') {
      // Simple heuristic: dump the whole template into subjective; user can cut+paste.
      noteForm.value.subjective = rendered
      noteForm.value.objective = ''
      noteForm.value.assessment = ''
      noteForm.value.plan = ''
    } else {
      noteForm.value.content_notes = rendered
    }
  } catch {
    // render failed
  }
}

function availableTemplates(): NoteTemplateMeta[] {
  // Filter by current note type if set (soft filter — shows all for 'addendum'/other)
  if (!noteForm.value.note_type) return templates.value
  return templates.value.filter(t => t.note_type === noteForm.value.note_type)
    .concat(templates.value.filter(t => t.note_type !== noteForm.value.note_type))
}

// ── New note form ─────────────────────────────────────────────────────────────
const showAddForm = ref(false)
const savingNote  = ref(false)
const noteError   = ref('')

const blankNote = () => ({
  note_type: 'soap', visit_type: 'in_center',
  visit_date: new Date().toISOString().slice(0, 10),
  subjective: '', objective: '', assessment: '', plan: '',
  content_notes: '',
  is_late_entry: false, late_entry_reason: '',
})
const noteForm = ref(blankNote())

const isSoap = computed(() => noteForm.value.note_type === 'soap')

async function submitNote() {
  savingNote.value = true
  noteError.value = ''
  try {
    const payload: Record<string, unknown> = {
      note_type:         noteForm.value.note_type,
      visit_type:        noteForm.value.visit_type,
      visit_date:        noteForm.value.visit_date,
      department:        auth.value?.user.department ?? '',
      is_late_entry:     noteForm.value.is_late_entry,
      late_entry_reason: noteForm.value.is_late_entry ? noteForm.value.late_entry_reason : null,
    }
    if (isSoap.value) {
      payload.subjective = noteForm.value.subjective
      payload.objective  = noteForm.value.objective
      payload.assessment = noteForm.value.assessment
      payload.plan       = noteForm.value.plan
    } else {
      payload.content = { notes: noteForm.value.content_notes }
    }
    // Phase I3 — template + problem linkage
    if (selectedTemplateId.value) payload.note_template_id = Number(selectedTemplateId.value)
    if (primaryProblemId.value)   payload.primary_problem_id = Number(primaryProblemId.value)
    if (secondaryProblemIds.value.length > 0) {
      payload.secondary_problem_ids = secondaryProblemIds.value
    }
    const { data } = await axios.post(`/participants/${props.participant.id}/notes`, payload)
    notes.value.unshift(data)
    showAddForm.value = false
    noteForm.value = blankNote()
    selectedTemplateId.value = ''
    primaryProblemId.value = ''
    secondaryProblemIds.value = []
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

    <!-- ── Header row ──────────────────────────────────────────────────────── -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-2">
          <DocumentTextIcon class="w-5 h-5 text-gray-400 dark:text-slate-500" />
          <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
            Clinical Notes ({{ notes.length }})
          </h2>
        </div>
        <!-- Status filter chips -->
        <div class="flex gap-1">
          <button
            v-for="s in ['all', 'draft', 'signed'] as const"
            :key="s"
            :class="[
              'text-xs px-2 py-0.5 rounded-full border transition-colors capitalize',
              statusFilter === s
                ? 'bg-blue-600 text-white border-blue-600'
                : 'text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700 hover:border-gray-300 dark:hover:border-slate-600',
            ]"
            @click="statusFilter = s"
          >{{ s === 'all' ? 'All' : s }}</button>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <!-- Note type dropdown filter -->
        <select name="typeFilter"
          v-model="typeFilter"
          class="text-xs border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200"
        >
          <option value="">All types</option>
          <option v-for="(label, key) in NOTE_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showAddForm = !showAddForm; if (showAddForm) loadTemplatesAndProblems()"
        >
          <PlusIcon class="w-3 h-3" />
          {{ showAddForm ? 'Cancel' : 'New Note' }}
        </button>
      </div>
    </div>

    <!-- ── New note form ───────────────────────────────────────────────────── -->
    <div
      v-if="showAddForm"
      class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 space-y-3"
    >
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">New Clinical Note</h3>

      <!-- Phase I3 — Template picker -->
      <div v-if="templates.length > 0" class="flex items-end gap-2">
        <div class="flex-1">
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Start from template (optional)</label>
          <select v-model="selectedTemplateId" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200" data-testid="note-template-picker">
            <option value="">— No template —</option>
            <option v-for="t in availableTemplates()" :key="t.id" :value="t.id">
              {{ t.name }} · {{ t.note_type }}{{ t.is_system ? ' · system' : '' }}
            </option>
          </select>
        </div>
        <button type="button" class="text-xs px-3 py-1.5 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20" :disabled="!selectedTemplateId" @click="applyTemplate">Apply</button>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Note Type</label>
          <select name="note_type" v-model="noteForm.note_type" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200">
            <option v-for="(label, key) in NOTE_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Visit Type</label>
          <select name="visit_type" v-model="noteForm.visit_type" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200">
            <option value="in_center">In Center</option>
            <option value="home_visit">Home Visit</option>
            <option value="telehealth">Telehealth</option>
            <option value="phone">Phone</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Visit Date</label>
          <input type="date" v-model="noteForm.visit_date" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200" />
        </div>
      </div>

      <!-- Phase I3 — Problem linkage -->
      <div v-if="problems.length > 0" class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Primary problem (optional)</label>
          <select v-model="primaryProblemId" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200" data-testid="note-primary-problem">
            <option value="">— None —</option>
            <option v-for="p in problems" :key="p.id" :value="p.id">
              {{ p.icd10_description }} ({{ p.icd10_code }})
            </option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Secondary problems (optional)</label>
          <select v-model="secondaryProblemIds" multiple class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200 h-20">
            <option v-for="p in problems.filter(x => x.id !== Number(primaryProblemId))" :key="p.id" :value="p.id">
              {{ p.icd10_description }} ({{ p.icd10_code }})
            </option>
          </select>
          <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">Cmd/Ctrl-click to select multiple.</p>
        </div>
      </div>

      <!-- SOAP fields -->
      <template v-if="isSoap">
        <div v-for="field in ['subjective', 'objective', 'assessment', 'plan']" :key="field">
          <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1 capitalize">{{ field }}</label>
          <textarea
            v-model="(noteForm as Record<string, string>)[field]"
            rows="2"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200 resize-none"
          />
        </div>
      </template>

      <!-- Non-SOAP: single notes textarea -->
      <template v-else>
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Notes</label>
          <textarea
            v-model="noteForm.content_notes"
            rows="4"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200 resize-none"
          />
        </div>
      </template>

      <!-- Late entry -->
      <div class="flex items-center gap-2">
        <input id="late-entry" v-model="noteForm.is_late_entry" type="checkbox" class="rounded" />
        <label for="late-entry" class="text-xs text-gray-600 dark:text-slate-400">Late Entry</label>
      </div>
      <div v-if="noteForm.is_late_entry">
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Late Entry Reason</label>
        <input v-model="noteForm.late_entry_reason" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-200" />
      </div>

      <p v-if="noteError" class="text-red-600 dark:text-red-400 text-xs">{{ noteError }}</p>

      <div class="flex gap-2">
        <button
          :disabled="savingNote"
          class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          @click="submitNote"
        >{{ savingNote ? 'Saving...' : 'Save Draft' }}</button>
        <button
          class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          @click="showAddForm = false"
        >Cancel</button>
      </div>
    </div>

    <!-- ── Notes list ──────────────────────────────────────────────────────── -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">
      Loading notes...
    </div>
    <div v-else-if="loadErr" class="py-8 text-center text-red-500 text-sm">{{ loadErr }}</div>
    <div v-else-if="filteredNotes.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">
      No notes found.
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="note in filteredNotes"
        :key="note.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden"
      >
        <!-- Note header (always visible, click to expand) -->
        <button
          class="w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
          @click="toggleExpand(note.id)"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
                {{ NOTE_TYPE_LABELS[note.note_type] ?? note.note_type }}
              </span>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_COLORS[note.status] ?? '']">
                {{ note.status }}
              </span>
              <span
                v-if="note.is_late_entry"
                class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-2 py-0.5 rounded-full"
              >Late Entry</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
              {{ fmtDate(note.visit_date) }}
              <span v-if="note.author"> · {{ note.author.first_name }} {{ note.author.last_name }}</span>
            </div>
            <!-- Preview (collapsed only) -->
            <p
              v-if="!expandedIds.has(note.id) && notePreview(note)"
              class="text-xs text-gray-500 dark:text-slate-400 mt-1 line-clamp-2"
            >{{ notePreview(note) }}</p>
          </div>
          <ChevronDownIcon
            :class="['w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform shrink-0 mt-0.5', expandedIds.has(note.id) ? 'rotate-180' : '']"
          />
        </button>

        <!-- Expanded content -->
        <div v-if="expandedIds.has(note.id)" class="border-t border-gray-100 dark:border-slate-700 px-4 py-3 space-y-3">

          <!-- SOAP sections -->
          <template v-if="note.note_type === 'soap'">
            <div class="grid grid-cols-2 gap-4">
              <template v-for="[label, val] in [['Subjective (S)', note.subjective], ['Objective (O)', note.objective], ['Assessment (A)', note.assessment], ['Plan (P)', note.plan]]" :key="label">
                <div v-if="val">
                  <dt class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{{ label }}</dt>
                  <dd class="mt-0.5 text-xs text-gray-800 dark:text-slate-200 whitespace-pre-wrap">{{ val }}</dd>
                </div>
              </template>
            </div>
          </template>

          <!-- Non-SOAP: content.notes -->
          <template v-else>
            <p class="text-xs text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
              {{ note.content?.notes ?? '' }}
            </p>
          </template>

          <!-- Site badge -->
          <div v-if="note.site" class="text-xs text-gray-400 dark:text-slate-500">
            Site: {{ note.site.name }}
          </div>

          <!-- Action row -->
          <div class="flex items-center gap-2 pt-1">
            <!-- Sign (own draft notes only) -->
            <button
              v-if="note.status === 'draft' && note.authored_by_user_id === auth?.user.id"
              :disabled="signingId === note.id"
              class="text-xs px-2.5 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
              @click.stop="signNote(note.id)"
            >{{ signingId === note.id ? 'Signing...' : 'Sign Note' }}</button>

            <!-- Addendum (signed notes) -->
            <template v-if="note.signed_at">
              <span class="text-xs text-gray-400 dark:text-slate-500 ml-auto">
                Signed {{ new Date(note.signed_at).toLocaleString('en-US') }}
              </span>
              <button
                :disabled="addendumParentId === note.id"
                class="text-xs px-2.5 py-1 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-950/40 disabled:opacity-50 transition-colors"
                @click.stop="openAddendumCompose(note.id)"
                data-testid="addendum-btn"
              ><span class="inline-flex items-center gap-1"><PlusIcon v-if="addendumParentId !== note.id" class="w-3 h-3" />{{ addendumParentId === note.id ? 'Adding...' : 'Add addendum' }}</span></button>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Phase I3 — Addendum compose modal -->
    <Teleport to="body">
      <div v-if="addendumComposeOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="closeAddendumCompose">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg p-6">
          <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Add addendum</h2>
          <p class="text-xs text-gray-500 dark:text-slate-400 mb-4">
            Addendum note will link to the original signed note. Addendum starts as a draft; you will sign it separately.
          </p>
          <form @submit.prevent="createAddendum" class="space-y-3">
            <textarea v-model="addendumComposeText" rows="6"
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-900 dark:text-slate-100 resize-none"
              placeholder="Additional information to append to the signed note…"
              data-testid="addendum-compose-input"
              required
            />
            <div class="flex items-center justify-end gap-2">
              <button type="button" class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700" @click="closeAddendumCompose">Cancel</button>
              <button type="submit" :disabled="addendumSubmitting || addendumComposeText.trim().length < 3" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
                {{ addendumSubmitting ? 'Saving…' : 'Create addendum (draft)' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>
