<script setup lang="ts">
// ─── ParticipantHeader.vue ────────────────────────────────────────────────────
// Sticky header shown on every participant profile tab. Displays photo (or
// initials avatar), name, MRN, DOB, site badge, enrollment status, flag chips,
// and advance directive badges (DNR / POLST / No Directive). Emits tab-change
// events so the parent shell can switch to Care Plan or trigger edit.
// Photo upload/delete handled locally via axios. Break-the-glass button shown
// for eligible non-clinical-staff users.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import {
    ExclamationTriangleIcon,
    CameraIcon,
    XCircleIcon,
    BoltIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Flag {
    id: number
    flag_type: string
    description: string | null
    severity: 'low' | 'medium' | 'high' | 'critical'
    is_active: boolean
}

interface Participant {
    id: number
    mrn: string
    first_name: string
    last_name: string
    preferred_name: string | null
    dob: string
    enrollment_status: string
    photo_path: string | null
    site: { id: number; name: string }
    advance_directive_status: string | null
    advance_directive_type: string | null
}

const props = defineProps<{
    participant: Participant
    activeFlags: Flag[]
    activeTab: string
    canEdit: boolean
    canDelete: boolean
    hasBreakGlassAccess: boolean
    breakGlassExpiresAt: string | null
}>()

const emit = defineEmits<{
    'tab-change': [tab: string]
}>()

// ── Display constants ──────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    enrolled: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    referred: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
    intake: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
    pending: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
    disenrolled: 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400',
    deceased: 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-500',
}

const FLAG_SEVERITY_COLORS: Record<string, string> = {
    low: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
    medium: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
    high: 'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
    critical:
        'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
}

const FLAG_LABELS: Record<string, string> = {
    wheelchair: 'Wheelchair',
    stretcher: 'Stretcher',
    oxygen: 'Oxygen',
    behavioral: 'Behavioral',
    fall_risk: 'Fall Risk',
    wandering_risk: 'Wandering Risk',
    isolation: 'Isolation',
    dnr: 'DNR',
    weight_bearing_restriction: 'Weight Bearing',
    dietary_restriction: 'Dietary',
    elopement_risk: 'Elopement Risk',
    hospice: 'Hospice',
    other: 'Other',
}

// ── Photo state ────────────────────────────────────────────────────────────────

const photoPath = ref<string | null>(props.participant.photo_path)
const photoUploading = ref(false)
const photoInputRef = ref<HTMLInputElement | null>(null)

async function handlePhotoUpload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    photoUploading.value = true
    const formData = new FormData()
    formData.append('photo', file)
    try {
        const res = await axios.post(`/participants/${props.participant.id}/photo`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        photoPath.value = res.data.photo_path
    } catch {
        alert('Photo upload failed. Max 4 MB, jpg/png/webp only.')
    } finally {
        photoUploading.value = false
        if (photoInputRef.value) photoInputRef.value.value = ''
    }
}

async function handlePhotoDelete() {
    if (!confirm('Remove participant photo?')) return
    try {
        await axios.delete(`/participants/${props.participant.id}/photo`)
        photoPath.value = null
    } catch {
        alert('Failed to remove photo.')
    }
}

// ── Deactivate state ───────────────────────────────────────────────────────────

const showDeactivateModal = ref(false)
const deleting = ref(false)

function handleDelete() {
    deleting.value = true
    showDeactivateModal.value = false
    router.delete(`/participants/${props.participant.id}`)
}

// ── Date formatting ────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function age(dob: string): number {
    const d = new Date(dob.slice(0, 10) + 'T12:00:00')
    const now = new Date()
    let a = now.getFullYear() - d.getFullYear()
    if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
    return a
}
</script>

<template>
    <div
        class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 sticky top-0 z-20 shadow-sm"
    >
        <div class="flex items-start gap-4">
            <!-- Photo / initials avatar with camera overlay -->
            <div class="relative flex-shrink-0 group">
                <img
                    v-if="photoPath"
                    :src="`/storage/${photoPath}`"
                    :alt="`${participant.first_name} ${participant.last_name}`"
                    class="w-16 h-16 rounded-full object-cover border-2 border-gray-200 dark:border-slate-600"
                />
                <div
                    v-else
                    class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white text-xl font-bold"
                >
                    {{ participant.first_name[0] }}{{ participant.last_name[0] }}
                </div>

                <template v-if="canEdit">
                    <button
                        type="button"
                        :disabled="photoUploading"
                        class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer disabled:cursor-wait"
                        title="Upload photo"
                        @click="photoInputRef?.click()"
                    >
                        <div
                            v-if="photoUploading"
                            class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"
                        ></div>
                        <CameraIcon v-else class="w-4 h-4 text-white" />
                    </button>
                    <button
                        v-if="photoPath"
                        type="button"
                        class="absolute -top-1 -right-1 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                        title="Remove photo"
                        @click="handlePhotoDelete"
                    >
                        <XCircleIcon
                            class="w-5 h-5 text-red-500 bg-white dark:bg-slate-800 rounded-full"
                        />
                    </button>
                    <input
                        ref="photoInputRef"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                        @change="handlePhotoUpload"
                    />
                </template>
            </div>

            <!-- Name, MRN, DOB, site -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-base font-semibold text-gray-900 dark:text-slate-100">
                        {{ participant.first_name }} {{ participant.last_name }}
                        <span
                            v-if="participant.preferred_name"
                            class="text-gray-400 dark:text-slate-500 font-normal text-sm ml-1"
                        >
                            "{{ participant.preferred_name }}"
                        </span>
                    </h1>
                    <span
                        :class="[
                            'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                            STATUS_COLORS[participant.enrollment_status] ??
                                'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
                        ]"
                    >
                        {{ participant.enrollment_status }}
                    </span>
                </div>

                <div class="flex items-center gap-3 mt-1 flex-wrap">
                    <span
                        class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded"
                    >
                        {{ participant.mrn }}
                    </span>
                    <span class="text-xs text-gray-500 dark:text-slate-400">
                        {{ fmtDate(participant.dob) }}
                        <span class="ml-1 text-gray-400 dark:text-slate-500"
                            >({{ age(participant.dob) }} yrs)</span
                        >
                    </span>
                    <span
                        class="text-xs bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 px-2 py-0.5 rounded"
                    >
                        {{ participant.site.name }}
                    </span>
                </div>

                <!-- Flag pills + advance directive badges -->
                <div
                    v-if="activeFlags.length > 0 || participant.advance_directive_status"
                    class="flex flex-wrap gap-1 mt-2 items-start"
                >
                    <span
                        v-for="flag in activeFlags"
                        :key="flag.id"
                        :title="flag.description ?? flag.flag_type"
                        :class="[
                            'inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium',
                            FLAG_SEVERITY_COLORS[flag.severity] ?? '',
                        ]"
                    >
                        {{ FLAG_LABELS[flag.flag_type] ?? flag.flag_type }}
                    </span>

                    <!-- DNR badge -->
                    <span
                        v-if="participant.advance_directive_type === 'dnr'"
                        class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border-red-300 dark:border-red-700"
                    >
                        DNR
                    </span>
                    <!-- POLST badge -->
                    <span
                        v-else-if="participant.advance_directive_type === 'polst'"
                        class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-700"
                    >
                        POLST
                    </span>
                    <!-- Other active directive on file -->
                    <span
                        v-else-if="participant.advance_directive_status === 'has_directive'"
                        class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 border-gray-300 dark:border-slate-600"
                    >
                        Advance Directive on File
                    </span>
                    <!-- No directive -->
                    <span
                        v-else-if="
                            [
                                'declined_directive',
                                'unknown',
                                'incapacitated_no_directive',
                            ].includes(participant.advance_directive_status ?? '')
                        "
                        class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 border-gray-300 dark:border-slate-600"
                    >
                        No Directive
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-shrink-0">
                <!-- Break-the-glass indicator -->
                <span
                    v-if="hasBreakGlassAccess"
                    class="inline-flex items-center gap-1 text-xs px-2 py-1 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 rounded-md border border-red-300 dark:border-red-700"
                >
                    <BoltIcon class="w-3 h-3" />
                    Emergency Access Active
                </span>

                <button
                    class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                    @click="emit('tab-change', 'care_plan')"
                >
                    Care Plan
                </button>
                <a
                    href="/schedule"
                    class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                >
                    Schedule
                </a>

                <template v-if="canDelete">
                    <button
                        :disabled="deleting"
                        class="text-xs px-3 py-1.5 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-50"
                        @click="showDeactivateModal = true"
                    >
                        {{ deleting ? 'Deactivating...' : 'Deactivate' }}
                    </button>
                </template>
            </div>
        </div>
    </div>

    <!-- Deactivate confirmation modal -->
    <div
        v-if="showDeactivateModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg mx-4 p-6">
            <div class="flex items-start gap-3 mb-4">
                <div
                    class="flex-shrink-0 w-9 h-9 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center"
                >
                    <ExclamationTriangleIcon class="w-5 h-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">
                        Deactivate participant record?
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {{ participant.first_name }} {{ participant.last_name }} &middot;
                        {{ participant.mrn }}
                    </p>
                </div>
            </div>
            <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
                This is a data correction tool, not a clinical workflow. Use it only when a record
                was created in error. The record will be hidden but is not permanently deleted.
            </p>
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-slate-700">
                <button
                    class="text-sm px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                    @click="showDeactivateModal = false"
                >
                    Cancel
                </button>
                <button
                    class="text-sm px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                    @click="handleDelete"
                >
                    Yes, deactivate
                </button>
            </div>
        </div>
    </div>
</template>
