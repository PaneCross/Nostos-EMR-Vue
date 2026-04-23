<script setup lang="ts">
// ─── Committees/Index.vue ────────────────────────────────────────────────────
// Phase 15-UI. Committee management (QAPI, IDT oversight, formulary, etc.).
// List + create committees, schedule meetings, record minutes + votes inline.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, reactive } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface Meeting {
    id: number
    scheduled_date: string
    status: string
    location: string | null
    agenda: string | null
    minutes: string | null
    held_at: string | null
}
interface Committee {
    id: number
    name: string
    committee_type: string
    charter: string | null
    meeting_cadence: string | null
    is_active: boolean
    members_count: number
    meetings_count: number
    meetings: Meeting[]
}

const props = defineProps<{
    committees: Committee[]
    types: string[]
    roles: string[]
}>()

const committees = ref<Committee[]>([...props.committees])
const showCreate = ref(false)
const expanded = ref<number | null>(null)
const busy = ref(false)
const error = ref('')

const form = reactive({
    name: '', committee_type: 'qapi',
    charter: '', meeting_cadence: 'monthly',
})

const meetingForm = reactive<{ scheduled_date: string; location: string; agenda: string }>({
    scheduled_date: '', location: '', agenda: '',
})

const voteForm = reactive({
    motion_text: '', votes_yes: 0, votes_no: 0, votes_abstain: 0,
    outcome: 'passed' as 'passed'|'failed'|'tabled'|'pending',
    notes: '',
})
const activeMeetingId = ref<number | null>(null)

async function createCommittee() {
    busy.value = true; error.value = ''
    try {
        const r = await axios.post('/committees', form)
        committees.value = [{ ...r.data.committee, members_count: 0, meetings_count: 0, meetings: [] }, ...committees.value]
        showCreate.value = false
        form.name = ''; form.charter = ''
    } catch (e: any) { error.value = e?.response?.data?.message ?? 'Create failed.' }
    finally { busy.value = false }
}

async function scheduleMeeting(committee: Committee) {
    if (!meetingForm.scheduled_date) return
    busy.value = true; error.value = ''
    try {
        const r = await axios.post(`/committees/${committee.id}/meetings`, meetingForm)
        committee.meetings = [r.data.meeting, ...committee.meetings]
        committee.meetings_count++
        meetingForm.scheduled_date = ''; meetingForm.location = ''; meetingForm.agenda = ''
    } catch (e: any) { error.value = e?.response?.data?.message ?? 'Schedule failed.' }
    finally { busy.value = false }
}

async function recordVote() {
    if (!activeMeetingId.value) return
    busy.value = true; error.value = ''
    try {
        await axios.post(`/committee-meetings/${activeMeetingId.value}/votes`, voteForm)
        voteForm.motion_text = ''; voteForm.votes_yes = 0; voteForm.votes_no = 0; voteForm.votes_abstain = 0
        voteForm.notes = ''
        activeMeetingId.value = null
    } catch (e: any) { error.value = e?.response?.data?.message ?? 'Vote record failed.' }
    finally { busy.value = false }
}

async function markHeld(meeting: Meeting) {
    const minutes = window.prompt('Meeting minutes:') ?? ''
    if (!minutes) return
    await axios.patch(`/committee-meetings/${meeting.id}`, {
        status: 'held', minutes,
    })
    meeting.status = 'held'; meeting.minutes = minutes; meeting.held_at = new Date().toISOString()
}
</script>

<template>
    <AppShell title="Committees">
        <Head title="Committees" />
        <div class="max-w-5xl mx-auto p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Committees</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Governing board, QAPI, IDT oversight, formulary — meetings, minutes, votes.</p>
                </div>
                <button @click="showCreate = !showCreate"
                    class="px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                    {{ showCreate ? 'Cancel' : '+ New committee' }}
                </button>
            </div>

            <div v-if="showCreate" class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Name</span>
                        <input v-model="form.name" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Type</span>
                        <select v-model="form.committee_type" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                            <option v-for="t in types" :key="t" :value="t">{{ t.replace(/_/g, ' ') }}</option>
                        </select>
                    </label>
                </div>
                <label class="text-sm block">
                    <span class="text-slate-600 dark:text-slate-400">Charter</span>
                    <textarea v-model="form.charter" rows="3"
                        class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                </label>
                <label class="text-sm block">
                    <span class="text-slate-600 dark:text-slate-400">Meeting cadence</span>
                    <input v-model="form.meeting_cadence" placeholder="monthly | quarterly | as_needed"
                        class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                </label>
                <button @click="createCommittee" :disabled="busy || !form.name"
                    class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">Save</button>
                <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
            </div>

            <div class="space-y-3">
                <div v-if="committees.length === 0" class="bg-white dark:bg-slate-800 border rounded-xl p-6 text-center text-slate-400">
                    No committees yet.
                </div>
                <div v-for="c in committees" :key="c.id"
                    class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl">
                    <div class="p-4 flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ c.name }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                {{ c.committee_type.replace(/_/g, ' ') }}
                                · {{ c.members_count }} member(s) · {{ c.meetings_count }} meeting(s)
                                <span v-if="c.meeting_cadence"> · {{ c.meeting_cadence }}</span>
                            </div>
                            <p v-if="c.charter" class="text-sm text-slate-600 dark:text-slate-300 mt-1">{{ c.charter }}</p>
                        </div>
                        <button @click="expanded = expanded === c.id ? null : c.id"
                            class="text-sm text-blue-600 dark:text-blue-400">
                            {{ expanded === c.id ? '▲' : 'Meetings ▼' }}
                        </button>
                    </div>

                    <div v-if="expanded === c.id" class="border-t border-gray-100 dark:border-slate-700 p-4 space-y-4">
                        <!-- Schedule new meeting -->
                        <div class="bg-slate-50 dark:bg-slate-900/40 rounded p-3 space-y-2">
                            <div class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Schedule meeting</div>
                            <div class="grid grid-cols-3 gap-2">
                                <input v-model="meetingForm.scheduled_date" type="date"
                                    class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700" />
                                <input v-model="meetingForm.location" placeholder="Location"
                                    class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700" />
                                <button @click="scheduleMeeting(c)" :disabled="busy || !meetingForm.scheduled_date"
                                    class="text-sm px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">Schedule</button>
                            </div>
                            <textarea v-model="meetingForm.agenda" rows="2" placeholder="Agenda (optional)"
                                class="w-full text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700"></textarea>
                        </div>

                        <!-- Recent meetings -->
                        <div class="space-y-2">
                            <div v-for="m in c.meetings" :key="m.id"
                                class="border border-slate-200 dark:border-slate-700 rounded p-3 text-sm">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ m.scheduled_date }}</span>
                                        <span class="ml-2 text-xs text-slate-500">{{ m.status }}</span>
                                        <span v-if="m.location" class="ml-2 text-xs text-slate-500">· {{ m.location }}</span>
                                    </div>
                                    <div class="space-x-2">
                                        <button v-if="m.status === 'scheduled'" @click="markHeld(m)"
                                            class="text-xs text-emerald-600 dark:text-emerald-400">Mark held + minutes</button>
                                        <button @click="activeMeetingId = m.id"
                                            class="text-xs text-blue-600 dark:text-blue-400">Record vote</button>
                                    </div>
                                </div>
                                <p v-if="m.agenda" class="text-xs text-slate-600 dark:text-slate-400 mt-1">Agenda: {{ m.agenda }}</p>
                                <p v-if="m.minutes" class="text-xs text-slate-600 dark:text-slate-400 mt-1 whitespace-pre-wrap">{{ m.minutes }}</p>

                                <!-- Inline vote form -->
                                <div v-if="activeMeetingId === m.id"
                                    class="mt-2 p-3 border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/40 rounded space-y-2">
                                    <textarea v-model="voteForm.motion_text" rows="2" placeholder="Motion text"
                                        class="w-full text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800"></textarea>
                                    <div class="grid grid-cols-4 gap-2">
                                        <input v-model.number="voteForm.votes_yes" type="number" placeholder="Yes" class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800" />
                                        <input v-model.number="voteForm.votes_no" type="number" placeholder="No" class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800" />
                                        <input v-model.number="voteForm.votes_abstain" type="number" placeholder="Abstain" class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800" />
                                        <select v-model="voteForm.outcome" class="text-sm rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                                            <option value="passed">passed</option>
                                            <option value="failed">failed</option>
                                            <option value="tabled">tabled</option>
                                            <option value="pending">pending</option>
                                        </select>
                                    </div>
                                    <div class="flex gap-2">
                                        <button @click="recordVote" :disabled="busy || !voteForm.motion_text"
                                            class="text-xs px-3 py-1.5 bg-emerald-600 text-white rounded hover:bg-emerald-700 disabled:opacity-50">Save vote</button>
                                        <button @click="activeMeetingId = null" class="text-xs text-slate-500">Cancel</button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="c.meetings.length === 0" class="text-xs italic text-slate-400">No meetings yet.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
