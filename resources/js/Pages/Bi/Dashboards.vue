<script setup lang="ts">
// ─── Dashboards.vue — Phase K2 ───────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { PlusIcon } from '@heroicons/vue/24/outline'

const dashboards = ref<any[]>([])
const loading = ref(true)
const showForm = ref(false)
const form = ref({ title: '', description: '', is_shared: false })
const saving = ref(false)
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  axios.get('/bi/dashboards')
    .then(r => dashboards.value = r.data.dashboards ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function submit() {
  saving.value = true
  error.value = null
  try {
    await axios.post('/bi/dashboards', { ...form.value, widgets: [] })
    showForm.value = false
    form.value = { title: '', description: '', is_shared: false }
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally { saving.value = false }
}
</script>

<template>
  <Head title="Saved Dashboards" />
  <AppShell>
    <div class="p-6 space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Saved Dashboards</h1>
        <div class="flex gap-2">
          <Link href="/bi/builder" class="rounded bg-gray-600 px-3 py-1.5 text-sm text-white hover:bg-gray-700">
            Report builder
          </Link>
          <button class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700" @click="showForm = !showForm">
            <PlusIcon class="h-4 w-4" />
            {{ showForm ? 'Cancel' : 'New dashboard' }}
          </button>
        </div>
      </div>

      <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4 space-y-3">
        <input v-model="form.title" placeholder="Title" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        <textarea v-model="form.description" rows="2" placeholder="Description" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" v-model="form.is_shared" />
          <span>Share with team</span>
        </label>
        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
        <div class="flex justify-end">
          <button :disabled="!form.title || saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
            {{ saving ? 'Saving…' : 'Create' }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="d in dashboards"
          :key="d.id"
          class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:shadow"
        >
          <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ d.title }}</h3>
          <p v-if="d.description" class="mt-1 text-xs text-gray-500 dark:text-slate-400">{{ d.description }}</p>
          <div class="mt-2 flex items-center gap-2 text-xs">
            <span v-if="d.is_shared" class="rounded bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-2 py-0.5">Shared</span>
            <span v-else class="rounded bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5">Private</span>
            <span class="text-gray-400">{{ (d.widgets ?? []).length }} widgets</span>
          </div>
        </div>
        <p v-if="!loading && dashboards.length === 0" class="col-span-full rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-8 text-center text-sm text-gray-500 dark:text-slate-400">
          No dashboards yet.
        </p>
      </div>
    </div>
  </AppShell>
</template>
