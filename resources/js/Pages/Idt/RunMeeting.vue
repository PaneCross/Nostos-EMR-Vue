<!--
  IDT Run Meeting — Interactive meeting conductor page.
  Left rail: participant queue sorted by queue_order; active participant highlighted blue;
  reviewed participants show a green checkmark.
  Right panel: ReviewPanel for the selected participant with auto-save (2s debounce),
  action items (department + due date + description), and mark-reviewed button.
  Bottom section: meeting minutes textarea with 3s debounce auto-save.
  Completed meetings are fully read-only (locked).

  Route:   GET /idt/meetings/{id} -> Inertia::render('Idt/RunMeeting')
  Props:   meeting (IdtMeeting with participant_reviews eager-loaded)
-->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { CheckCircleIcon, UserCircleIcon } from '@heroicons/vue/24/solid'
import { ChevronLeftIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface ActionItem {
  description: string
  department: string
  due_date: string
}

interface ParticipantReview {
  id: number
  queue_order: number
  reviewed_at: string | null
  summary_text: string | null
  action_items: ActionItem[] | null
  participant: {
    id: number
    mrn: string
    first_name: string
    last_name: string
  }
}

interface IdtMeeting {
  id: number
  meeting_date: string
  meeting_time: string | null
  meeting_type: string
  status: 'scheduled' | 'in_progress' | 'completed'
  minutes_text: string | null
  facilitator: { id: number; first_name: string; last_name: string } | null
  participant_reviews: ParticipantReview[]
}

const props = defineProps<{ meeting: IdtMeeting }>()

// ── Constants ─────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
  daily: 'Daily Huddle',
  weekly: 'Weekly IDT Review',
  care_plan_review: 'Care Plan Review',
  urgent: 'Urgent IDT',
}

const DEPT_OPTIONS = [
  { value: 'primary_care',       label: 'Primary Care' },
  { value: 'therapies',          label: 'Therapies' },
  { value: 'social_work',        label: 'Social Work' },
  { value: 'behavioral_health',  label: 'Behavioral Health' },
  { value: 'dietary',            label: 'Dietary' },
  { value: 'activities',         label: 'Activities' },
  { value: 'home_care',          label: 'Home Care' },
  { value: 'transportation',     label: 'Transportation' },
  { value: 'pharmacy',           label: 'Pharmacy' },
  { value: 'idt',                label: 'IDT' },
  { value: 'enrollment',         label: 'Enrollment' },
  { value: 'finance',            label: 'Finance' },
  { value: 'qa_compliance',      label: 'QA / Compliance' },
  { value: 'it_admin',           label: 'IT Admin' },
]

// ── State ─────────────────────────────────────────────────────────────────────

const locked = computed(() => props.meeting.status === 'completed')

const sortedReviews = computed(() =>
  [...props.meeting.participant_reviews].sort((a, b) => a.queue_order - b.queue_order)
)

const selectedReviewId = ref<number | null>(
  sortedReviews.value.find(r => !r.reviewed_at)?.id ?? sortedReviews.value[0]?.id ?? null
)

const selectedReview = computed<ParticipantReview | null>(
  () => sortedReviews.value.find(r => r.id === selectedReviewId.value) ?? null
)

// Per-participant editable state
const summaryText = ref('')
const actionItems = ref<ActionItem[]>([])

// Meeting minutes
const minutesText = ref(props.meeting.minutes_text ?? '')

// Save status indicators
const reviewSaving = ref(false)
const reviewSaved = ref(false)
const minutesSaving = ref(false)
const minutesSaved = ref(false)
const markingReviewed = ref(false)
const completing = ref(false)
const completeError = ref('')

// Debounce timer refs
let reviewSaveTimer: ReturnType<typeof setTimeout> | null = null
let minutesSaveTimer: ReturnType<typeof setTimeout> | null = null

// ── Load review into editable state when selection changes ────────────────────

watch(
  selectedReviewId,
  (newId) => {
    const review = sortedReviews.value.find(r => r.id === newId)
    if (review) {
      summaryText.value = review.summary_text ?? ''
      actionItems.value = review.action_items ? JSON.parse(JSON.stringify(review.action_items)) : []
    }
    reviewSaved.value = false
    if (reviewSaveTimer) clearTimeout(reviewSaveTimer)
  },
  { immediate: true }
)

// ── Review auto-save (2s debounce) ────────────────────────────────────────────

function scheduleReviewSave() {
  if (locked.value || !selectedReviewId.value) return
  if (reviewSaveTimer) clearTimeout(reviewSaveTimer)
  reviewSaveTimer = setTimeout(() => saveReviewNow(), 2000)
}

async function saveReviewNow() {
  if (locked.value || !selectedReviewId.value) return
  reviewSaving.value = true
  try {
    await axios.patch(
      `/idt/meetings/${props.meeting.id}/participants/${selectedReviewId.value}`,
      { summary_text: summaryText.value, action_items: actionItems.value }
    )
    reviewSaved.value = true
    setTimeout(() => { reviewSaved.value = false }, 2500)
  } catch {
    // Non-blocking auto-save failure
  } finally {
    reviewSaving.value = false
  }
}

// ── Mark reviewed ─────────────────────────────────────────────────────────────

async function markReviewed() {
  if (locked.value || !selectedReviewId.value) return
  markingReviewed.value = true
  try {
    await saveReviewNow()
    await axios.post(
      `/idt/meetings/${props.meeting.id}/participants/${selectedReviewId.value}/reviewed`
    )
    // Auto-advance to next unreviewed participant
    const current = sortedReviews.value.find(r => r.id === selectedReviewId.value)
    const next = sortedReviews.value.find(
      r => !r.reviewed_at && r.id !== selectedReviewId.value
    )
    router.reload({ only: ['meeting'] })
    if (next) selectedReviewId.value = next.id
  } catch {
    // Non-blocking
  } finally {
    markingReviewed.value = false
  }
}

// ── Action items ──────────────────────────────────────────────────────────────

function addActionItem() {
  actionItems.value.push({ description: '', department: 'primary_care', due_date: '' })
  scheduleReviewSave()
}

function removeActionItem(index: number) {
  actionItems.value.splice(index, 1)
  scheduleReviewSave()
}

// ── Meeting minutes auto-save (3s debounce) ───────────────────────────────────

function scheduleMinutesSave() {
  if (locked.value) return
  if (minutesSaveTimer) clearTimeout(minutesSaveTimer)
  minutesSaveTimer = setTimeout(() => saveMinutesNow(), 3000)
}

async function saveMinutesNow() {
  if (locked.value) return
  minutesSaving.value = true
  try {
    await axios.patch(`/idt/meetings/${props.meeting.id}`, { minutes_text: minutesText.value })
    minutesSaved.value = true
    setTimeout(() => { minutesSaved.value = false }, 2500)
  } catch {
    // Non-blocking
  } finally {
    minutesSaving.value = false
  }
}

// ── Complete meeting ──────────────────────────────────────────────────────────

async function completeMeeting() {
  if (!window.confirm('Mark this meeting as completed? This cannot be undone.')) return
  completing.value = true
  completeError.value = ''
  try {
    await axios.post(`/idt/meetings/${props.meeting.id}/complete`)
    router.reload()
  } catch (e: any) {
    completeError.value = e.response?.data?.message ?? 'Failed to complete meeting.'
  } finally {
    completing.value = false
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt12h(time: string | null): string {
  if (!time) return ''
  const [h, m] = time.split(':').map(Number)
  const ampm = h >= 12 ? 'PM' : 'AM'
  const hour = h % 12 || 12
  return `${hour}:${String(m).padStart(2, '0')} ${ampm}`
}

function reviewedCount(): number {
  return sortedReviews.value.filter(r => r.reviewed_at).length
}

function fmtDate(dateStr: string): string {
  return new Date(dateStr.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <AppShell>
    <Head :title="`IDT Meeting - ${fmtDate(props.meeting.meeting_date)}`" />

    <div class="flex flex-col h-full">

      <!-- Top bar -->
      <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-between gap-4 shrink-0">
        <div class="flex items-center gap-3">
          <a
            href="/idt"
            class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 transition"
            aria-label="Back to IDT Dashboard"
          >
            <ChevronLeftIcon class="w-5 h-5" aria-hidden="true" />
          </a>
          <div>
            <h1 class="text-base font-bold text-gray-900 dark:text-slate-100">
              {{ TYPE_LABELS[props.meeting.meeting_type] ?? props.meeting.meeting_type }}
            </h1>
            <p class="text-xs text-gray-500 dark:text-slate-400">
              {{ fmtDate(props.meeting.meeting_date) }}
              <template v-if="props.meeting.meeting_time"> · {{ fmt12h(props.meeting.meeting_time) }}</template>
              <template v-if="props.meeting.facilitator">
                · {{ props.meeting.facilitator.first_name }} {{ props.meeting.facilitator.last_name }}
              </template>
              · {{ reviewedCount() }}/{{ sortedReviews.length }} reviewed
            </p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Locked badge -->
          <span
            v-if="locked"
            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 rounded-full ring-1 ring-green-600/20"
          >
            <CheckCircleIcon class="w-3.5 h-3.5" aria-hidden="true" />
            Completed
          </span>

          <!-- Complete meeting button -->
          <button
            v-if="!locked"
            :disabled="completing"
            class="px-3 py-1.5 text-sm font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition"
            @click="completeMeeting"
          >
            {{ completing ? 'Completing...' : 'Complete Meeting' }}
          </button>
        </div>
      </div>

      <p v-if="completeError" class="px-6 py-2 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60">
        {{ completeError }}
      </p>

      <!-- Main layout: sidebar + content -->
      <div class="flex flex-1 min-h-0 overflow-hidden">

        <!-- Left rail: participant queue -->
        <aside class="w-60 shrink-0 border-r border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-900 overflow-y-auto">
          <div class="p-3 space-y-1">
            <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider px-2 py-1">
              Participants
            </p>
            <button
              v-for="review in sortedReviews"
              :key="review.id"
              :class="[
                'w-full flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm transition text-left',
                selectedReviewId === review.id
                  ? 'bg-blue-600 text-white'
                  : 'text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700/50',
              ]"
              @click="selectedReviewId = review.id"
            >
              <span class="shrink-0">
                <CheckCircleIcon
                  v-if="review.reviewed_at"
                  class="w-4 h-4 text-green-500"
                  aria-label="Reviewed"
                />
                <UserCircleIcon
                  v-else
                  :class="['w-4 h-4', selectedReviewId === review.id ? 'text-blue-200' : 'text-gray-400 dark:text-slate-500']"
                  aria-hidden="true"
                />
              </span>
              <span class="truncate font-medium">
                {{ review.participant.last_name }}, {{ review.participant.first_name }}
              </span>
            </button>

            <p v-if="sortedReviews.length === 0" class="text-xs text-gray-400 dark:text-slate-500 px-2 py-2">
              No participants in queue.
            </p>
          </div>
        </aside>

        <!-- Right panel -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">

          <!-- No participant selected -->
          <div
            v-if="!selectedReview"
            class="flex items-center justify-center h-48 text-sm text-gray-400 dark:text-slate-500"
          >
            Select a participant from the queue to begin review.
          </div>

          <template v-else>
            <!-- Participant header -->
            <div class="flex items-center justify-between">
              <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100">
                  {{ selectedReview.participant.last_name }}, {{ selectedReview.participant.first_name }}
                </h2>
                <p class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">
                  MRN: {{ selectedReview.participant.mrn }}
                </p>
              </div>
              <div class="flex items-center gap-2">
                <span
                  v-if="reviewSaving"
                  class="text-xs text-gray-400 dark:text-slate-500"
                >
                  Saving...
                </span>
                <span
                  v-else-if="reviewSaved"
                  class="text-xs text-green-600 dark:text-green-400"
                >
                  Saved
                </span>
                <span
                  v-if="selectedReview.reviewed_at"
                  class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 rounded ring-1 ring-green-600/20"
                >
                  <CheckCircleIcon class="w-3 h-3" aria-hidden="true" />
                  Reviewed
                </span>
                <button
                  v-if="!locked && !selectedReview.reviewed_at"
                  :disabled="markingReviewed"
                  class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition"
                  @click="markReviewed"
                >
                  {{ markingReviewed ? 'Marking...' : 'Mark Reviewed' }}
                </button>
              </div>
            </div>

            <!-- Summary text -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                Review Summary
              </label>
              <textarea
                v-model="summaryText"
                :disabled="locked"
                rows="4"
                placeholder="Enter clinical summary, key findings, and decisions made during review..."
                class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm px-3 py-2 dark:bg-slate-700 disabled:opacity-60 disabled:cursor-not-allowed resize-y"
                @input="scheduleReviewSave"
              />
            </div>

            <!-- Action items -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-gray-700 dark:text-slate-300">
                  Action Items
                </label>
                <button
                  v-if="!locked"
                  class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium"
                  @click="addActionItem"
                >
                  <PlusIcon class="w-3.5 h-3.5" aria-hidden="true" />
                  Add Item
                </button>
              </div>

              <div
                v-if="actionItems.length === 0"
                class="rounded-lg border border-dashed border-gray-200 dark:border-slate-700 px-4 py-3 text-xs text-gray-400 dark:text-slate-500 text-center"
              >
                No action items. {{ !locked ? 'Click "Add Item" to add one.' : '' }}
              </div>

              <div class="space-y-2">
                <div
                  v-for="(item, i) in actionItems"
                  :key="i"
                  class="flex items-start gap-2 rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-900/50 px-3 py-2"
                >
                  <div class="flex-1 grid grid-cols-3 gap-2">
                    <div class="col-span-3">
                      <input
                        v-model="item.description"
                        :disabled="locked"
                        type="text"
                        placeholder="Action item description..."
                        class="block w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-2 py-1 dark:bg-slate-700 disabled:opacity-60"
                        @input="scheduleReviewSave"
                      />
                    </div>
                    <div>
                      <select name="department"
                        v-model="item.department"
                        :disabled="locked"
                        class="block w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-2 py-1 dark:bg-slate-700 disabled:opacity-60"
                        @change="scheduleReviewSave"
                      >
                        <option v-for="opt in DEPT_OPTIONS" :key="opt.value" :value="opt.value">
                          {{ opt.label }}
                        </option>
                      </select>
                    </div>
                    <div>
                      <input
                        v-model="item.due_date"
                        :disabled="locked"
                        type="date"
                        class="block w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-2 py-1 dark:bg-slate-700 disabled:opacity-60"
                        @input="scheduleReviewSave"
                      />
                    </div>
                  </div>
                  <button
                    v-if="!locked"
                    class="mt-0.5 text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition shrink-0"
                    :aria-label="`Remove action item ${i + 1}`"
                    @click="removeActionItem(i)"
                  >
                    <XMarkIcon class="w-4 h-4" aria-hidden="true" />
                  </button>
                </div>
              </div>
            </div>
          </template>

          <!-- Meeting minutes (always visible) -->
          <div class="border-t border-gray-100 dark:border-slate-700 pt-6">
            <div class="flex items-center justify-between mb-2">
              <label class="text-sm font-medium text-gray-700 dark:text-slate-300">
                Meeting Minutes
              </label>
              <span v-if="minutesSaving" class="text-xs text-gray-400 dark:text-slate-500">Saving...</span>
              <span v-else-if="minutesSaved" class="text-xs text-green-600 dark:text-green-400">Saved</span>
            </div>
            <textarea
              v-model="minutesText"
              :disabled="locked"
              rows="6"
              placeholder="Record meeting minutes, decisions, and attendance notes here..."
              class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm px-3 py-2 dark:bg-slate-700 disabled:opacity-60 disabled:cursor-not-allowed resize-y"
              @input="scheduleMinutesSave"
            />
          </div>

        </div>
      </div>

    </div>
  </AppShell>
</template>
