<script setup lang="ts">
// ─── Executive / Job Titles ──────────────────────────────────────────────────
// Org-controlled vocabulary management. Executive defines the org's job-title
// list once ; titles populate the dropdown when creating/editing staff users
// and the targeting checkbox grid when defining credentials.
//
// Soft-deletes so retired titles don't break historical user.job_title strings.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import OrgSettingsTabBar from '@/Pages/Executive/components/OrgSettingsTabBar.vue'
import axios from 'axios'
import {
    BriefcaseIcon, PlusIcon, PencilSquareIcon, TrashIcon,
    InformationCircleIcon, CheckIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

interface JobTitle {
    id: number
    code: string
    label: string
    is_active: boolean
    sort_order: number
    deleted_at: string | null
}

const titles = ref<JobTitle[]>([])
const loading = ref(false)
const showAddModal = ref(false)
const editingTitle = ref<JobTitle | null>(null)
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

const newTitle = ref({ code: '', label: '', sort_order: 100, is_active: true })

const sortedTitles = computed(() =>
    [...titles.value].sort((a, b) => a.sort_order - b.sort_order || a.label.localeCompare(b.label))
)

async function loadTitles() {
    loading.value = true
    try {
        const { data } = await axios.get('/executive/job-titles')
        titles.value = data
    } finally {
        loading.value = false
    }
}

async function saveNew() {
    errorMessage.value = null
    if (!newTitle.value.code || !newTitle.value.label) {
        errorMessage.value = 'Code and label are required.'
        return
    }
    try {
        const { data } = await axios.post('/executive/job-titles', newTitle.value)
        titles.value.push(data)
        showAddModal.value = false
        newTitle.value = { code: '', label: '', sort_order: 100, is_active: true }
        flashSuccess(`Added "${data.label}".`)
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not save.'
    }
}

async function saveEdit() {
    if (!editingTitle.value) return
    try {
        const { data } = await axios.patch(`/executive/job-titles/${editingTitle.value.id}`, {
            label:      editingTitle.value.label,
            sort_order: editingTitle.value.sort_order,
            is_active:  editingTitle.value.is_active,
        })
        const idx = titles.value.findIndex(t => t.id === data.id)
        if (idx >= 0) titles.value[idx] = data
        editingTitle.value = null
        flashSuccess(`Updated "${data.label}".`)
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not save.'
    }
}

async function deactivate(t: JobTitle) {
    if (!confirm(`Deactivate "${t.label}"?\n\nAny users currently holding this title will be cleared (job_title set to null) so their credential targeting doesn't silently break. You'll need to reassign them to a different title for credentials to apply correctly.`)) return
    try {
        const { data } = await axios.delete(`/executive/job-titles/${t.id}`)
        titles.value = titles.value.filter(x => x.id !== t.id)
        flashSuccess(data.message ?? `Deactivated "${t.label}".`)
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not deactivate.'
    }
}

function flashSuccess(msg: string) {
    successMessage.value = msg
    setTimeout(() => successMessage.value = null, 3500)
}

onMounted(loadTitles)
</script>

<template>
    <AppShell>
        <Head title="Job Titles" />

        <div class="mx-auto px-6 py-8" style="max-width: min(1280px, calc(100vw - 80px));">
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <BriefcaseIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Job Titles
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        The vocabulary your org uses for staff job titles. Targets credential definitions and powers user-form dropdowns.
                    </p>
                </div>
                <button
                    @click="showAddModal = true"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium"
                >
                    <PlusIcon class="w-4 h-4" /> Add title
                </button>
            </div>

            <OrgSettingsTabBar active="job_titles" />

            <!-- Help banner -->
            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-4 mb-6 flex items-start gap-3 text-sm">
                <InformationCircleIcon class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100">
                    <p><strong>Tip:</strong> Job titles drive credential targeting. Example : the seeded "RN License" credential automatically applies to any user whose job title is set to <code class="text-xs">rn</code>.</p>
                    <p class="mt-1">Deactivating a title preserves existing user assignments but removes it from new-user dropdowns.</p>
                    <p class="mt-1"><strong>Sort order</strong> is purely a display preference : lower number appears earlier in dropdowns and lists. It does not affect targeting, permissions, or any other business logic. Use it to group related titles together (e.g. all clinical roles at 10-19, all support roles at 50-59).</p>
                </div>
            </div>

            <!-- Flash messages -->
            <div v-if="successMessage" class="rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40 text-emerald-800 dark:text-emerald-200 px-4 py-2 mb-4 text-sm flex items-center gap-2">
                <CheckIcon class="w-4 h-4" /> {{ successMessage }}
            </div>
            <div v-if="errorMessage" class="rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200 px-4 py-2 mb-4 text-sm">
                {{ errorMessage }}
            </div>

            <!-- Table -->
            <div class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800 text-gray-700 dark:text-slate-300">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Code</th>
                            <th class="text-left px-4 py-2 font-medium">Label</th>
                            <th class="text-right px-4 py-2 font-medium">Sort</th>
                            <th class="text-center px-4 py-2 font-medium">Status</th>
                            <th class="text-right px-4 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                        <tr v-if="loading">
                            <td colspan="5" class="text-center py-8 text-gray-500 dark:text-slate-400">Loading...</td>
                        </tr>
                        <tr v-else-if="sortedTitles.length === 0">
                            <td colspan="5" class="text-center py-8 text-gray-500 dark:text-slate-400">
                                No job titles configured. Click "Add title" to create one.
                            </td>
                        </tr>
                        <tr v-for="t in sortedTitles" :key="t.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-slate-300">{{ t.code }}</td>
                            <td class="px-4 py-2 text-gray-900 dark:text-slate-100">{{ t.label }}</td>
                            <td class="px-4 py-2 text-right text-gray-500 dark:text-slate-400">{{ t.sort_order }}</td>
                            <td class="px-4 py-2 text-center">
                                <span v-if="t.is_active" class="inline-block px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Active</span>
                                <span v-else class="inline-block px-2 py-0.5 rounded-full text-xs bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400">Inactive</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editingTitle = { ...t }" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 p-1 mr-1" title="Edit">
                                    <PencilSquareIcon class="w-4 h-4" />
                                </button>
                                <button @click="deactivate(t)" class="text-rose-500 hover:text-rose-700 p-1" title="Deactivate">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Add modal -->
            <div v-if="showAddModal" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" @click.self="showAddModal = false">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-md w-full p-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">Add job title</h2>
                    <div class="space-y-3">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Code (lowercase, underscores allowed)</span>
                            <input v-model="newTitle.code" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-mono" placeholder="e.g. rn, lcsw, driver" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Label</span>
                            <input v-model="newTitle.label" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" placeholder="e.g. Registered Nurse (RN)" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Sort order (lower = higher in list)</span>
                            <input v-model.number="newTitle.sort_order" type="number" min="0" max="9999" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 mt-6">
                        <button @click="showAddModal = false" class="px-4 py-2 rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="saveNew" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">Save</button>
                    </div>
                </div>
            </div>

            <!-- Edit modal -->
            <div v-if="editingTitle" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" @click.self="editingTitle = null">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-md w-full p-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">Edit job title</h2>
                    <div class="space-y-3">
                        <div>
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Code</span>
                            <p class="mt-1 px-3 py-2 rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800/50 text-sm font-mono text-gray-500 dark:text-slate-400">{{ editingTitle.code }} <span class="text-xs">(immutable)</span></p>
                        </div>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Label</span>
                            <input v-model="editingTitle.label" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Sort order</span>
                            <input v-model.number="editingTitle.sort_order" type="number" min="0" max="9999" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="editingTitle.is_active" type="checkbox" class="rounded text-indigo-600" />
                            <span class="text-sm text-gray-700 dark:text-slate-300">Active</span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 mt-6">
                        <button @click="editingTitle = null" class="px-4 py-2 rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="saveEdit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
