<script setup lang="ts">
// ─── CarePlanTab.vue ──────────────────────────────────────────────────────────
// Active care plan with goals and participation level. Shows editable fields
// for draft/under_review plans. Goals can be added inline. Version history
// displays archived plans. Emits tab-change to parent for IDT navigation.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'

interface CarePlanGoal {
    id: number
    domain: string
    goal_text: string
    target_date: string | null
    status: string
    interventions: string | null
}

interface CarePlan {
    id: number
    status: string
    version: number
    effective_date: string | null
    review_date: string | null
    participation_level: string | null
    patient_agrees: boolean
    goals: CarePlanGoal[]
    author: { id: number; first_name: string; last_name: string } | null
    created_at: string
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    carePlan?: CarePlan | null
    carePlanHistory?: CarePlan[]
}>()

const emit = defineEmits<{ 'tab-change': [tab: string] }>()

const plan = ref<CarePlan | null>(props.carePlan ?? null)
const history = ref<CarePlan[]>(props.carePlanHistory ?? [])
const showAddGoal = ref(false)
const savingGoal = ref(false)
const goalError = ref('')

const goalForm = ref({
    domain: 'medical',
    goal_text: '',
    target_date: '',
    interventions: '',
})

const STATUS_COLORS: Record<string, string> = {
    draft: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
    under_review: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
    approved: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    archived: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-500',
}

const GOAL_STATUS_COLORS: Record<string, string> = {
    in_progress: 'text-blue-600 dark:text-blue-400',
    met: 'text-green-600 dark:text-green-400',
    not_met: 'text-red-600 dark:text-red-400',
    discontinued: 'text-gray-400 dark:text-slate-500',
}

const isEditable = computed(
    () => plan.value && (plan.value.status === 'draft' || plan.value.status === 'under_review'),
)

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function addGoal() {
    if (!goalForm.value.goal_text.trim()) {
        goalError.value = 'Goal text is required.'
        return
    }
    if (!plan.value) return
    savingGoal.value = true
    goalError.value = ''
    try {
        const res = await axios.post(
            `/participants/${props.participant.id}/care-plans/${plan.value.id}/goals`,
            goalForm.value,
        )
        plan.value.goals.push(res.data)
        showAddGoal.value = false
        goalForm.value = { domain: 'medical', goal_text: '', target_date: '', interventions: '' }
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        goalError.value = e.response?.data?.message ?? 'Failed to save goal.'
        savingGoal.value = false
    }
}
</script>

<template>
    <div class="p-6">
        <div class="flex items-center gap-2 mb-4">
            <DocumentTextIcon class="w-5 h-5 text-gray-400 dark:text-slate-500" />
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Care Plan</h2>
        </div>

        <div v-if="!plan" class="py-12 text-center">
            <p class="text-gray-400 dark:text-slate-500 text-sm mb-3">No active care plan.</p>
            <p class="text-xs text-gray-400 dark:text-slate-500">
                Care plans are created during IDT meetings.
                <button
                    class="text-blue-600 dark:text-blue-400 underline ml-1"
                    @click="emit('tab-change', 'idt')"
                >
                    Go to IDT
                </button>
            </p>
        </div>

        <template v-else>
            <!-- Plan header -->
            <div
                class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4 mb-4"
            >
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span
                            :class="[
                                'text-xs px-2 py-0.5 rounded-full font-medium',
                                STATUS_COLORS[plan.status] ?? '',
                            ]"
                            >{{ plan.status.replace('_', ' ') }}</span
                        >
                        <span class="text-xs text-gray-500 dark:text-slate-400"
                            >Version {{ plan.version }}</span
                        >
                    </div>
                    <div class="text-xs text-gray-400 dark:text-slate-500">
                        by
                        {{
                            plan.author ? `${plan.author.first_name} ${plan.author.last_name}` : '-'
                        }}
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-slate-400">Effective Date</dt>
                        <dd class="text-gray-900 dark:text-slate-100">
                            {{ fmtDate(plan.effective_date) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-slate-400">Review Date</dt>
                        <dd class="text-gray-900 dark:text-slate-100">
                            {{ fmtDate(plan.review_date) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-slate-400">
                            Participation Level
                        </dt>
                        <dd class="text-gray-900 dark:text-slate-100 capitalize">
                            {{ plan.participation_level?.replace('_', ' ') ?? '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-slate-400">
                            Participant Agrees
                        </dt>
                        <dd
                            :class="
                                plan.patient_agrees
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-gray-500 dark:text-slate-400'
                            "
                        >
                            {{ plan.patient_agrees ? 'Yes' : 'No / Not documented' }}
                        </dd>
                    </div>
                </div>
            </div>

            <!-- Goals -->
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    Goals ({{ plan.goals.length }})
                </h3>
                <button
                    v-if="isEditable"
                    class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    @click="showAddGoal = !showAddGoal"
                >
                    <PlusIcon class="w-3 h-3" />
                    Add Goal
                </button>
            </div>

            <!-- Add goal form -->
            <div
                v-if="showAddGoal"
                class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-3"
            >
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Domain</label
                        >
                        <select
                            v-model="goalForm.domain"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        >
                            <option value="medical">Medical</option>
                            <option value="nursing">Nursing</option>
                            <option value="physical_therapy">Physical Therapy</option>
                            <option value="occupational_therapy">Occupational Therapy</option>
                            <option value="speech_therapy">Speech Therapy</option>
                            <option value="social_work">Social Work</option>
                            <option value="dietary">Dietary</option>
                            <option value="behavioral_health">Behavioral Health</option>
                            <option value="transportation">Transportation</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Target Date</label
                        >
                        <input
                            v-model="goalForm.target_date"
                            type="date"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div class="col-span-2">
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Goal *</label
                        >
                        <textarea
                            v-model="goalForm.goal_text"
                            rows="2"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        ></textarea>
                    </div>
                    <div class="col-span-2">
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Interventions</label
                        >
                        <textarea
                            v-model="goalForm.interventions"
                            rows="2"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        ></textarea>
                    </div>
                </div>
                <p v-if="goalError" class="text-red-600 dark:text-red-400 text-xs mb-2">
                    {{ goalError }}
                </p>
                <div class="flex gap-2">
                    <button
                        :disabled="savingGoal"
                        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                        @click="addGoal"
                    >
                        {{ savingGoal ? 'Saving...' : 'Save Goal' }}
                    </button>
                    <button
                        class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                        @click="showAddGoal = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>

            <div
                v-if="plan.goals.length === 0"
                class="py-6 text-center text-gray-400 dark:text-slate-500 text-sm"
            >
                No goals defined.
            </div>
            <div v-else class="space-y-2 mb-6">
                <div
                    v-for="goal in plan.goals"
                    :key="goal.id"
                    class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded mt-0.5 shrink-0 capitalize"
                            >{{ goal.domain.replace('_', ' ') }}</span
                        >
                        <div class="flex-1">
                            <p class="text-sm text-gray-900 dark:text-slate-100">
                                {{ goal.goal_text }}
                            </p>
                            <p
                                v-if="goal.interventions"
                                class="text-xs text-gray-500 dark:text-slate-400 mt-1"
                            >
                                {{ goal.interventions }}
                            </p>
                            <div class="flex items-center gap-3 mt-1">
                                <span
                                    :class="[
                                        'text-xs font-medium capitalize',
                                        GOAL_STATUS_COLORS[goal.status] ?? 'text-gray-500',
                                    ]"
                                    >{{ goal.status.replace('_', ' ') }}</span
                                >
                                <span
                                    v-if="goal.target_date"
                                    class="text-xs text-gray-400 dark:text-slate-500"
                                    >Target: {{ fmtDate(goal.target_date) }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Version history -->
            <div v-if="history.length > 0">
                <h3
                    class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2"
                >
                    Version History
                </h3>
                <div class="space-y-1">
                    <div
                        v-for="h in history"
                        :key="h.id"
                        class="flex items-center gap-3 text-xs text-gray-500 dark:text-slate-400 px-2 py-1.5 border border-gray-100 dark:border-slate-700 rounded"
                    >
                        <span>v{{ h.version }}</span>
                        <span :class="['px-1.5 py-0.5 rounded', STATUS_COLORS[h.status] ?? '']">{{
                            h.status
                        }}</span>
                        <span>{{ fmtDate(h.effective_date) }}</span>
                        <span class="ml-auto">{{ h.goals.length }} goals</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
