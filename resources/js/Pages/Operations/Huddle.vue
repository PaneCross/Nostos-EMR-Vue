<script setup lang="ts">
// ─── Operations/Huddle.vue: Phase K3 ────────────────────────────────────────
// Daily department huddle view. Quick at-a-glance summary used at morning
// stand-ups: today's appointments, alerts, urgent items per selected
// department. Department selector switches data source.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const loading = ref(true)
const data = ref<any>({})
const department = ref('primary_care')

const DEPTS = [
  'primary_care', 'home_care', 'pharmacy', 'social_work', 'qa_compliance',
  'idt', 'dietary', 'activities', 'transportation', 'therapies', 'executive',
]

function refresh() {
  loading.value = true
  axios.get('/huddle', { params: { department: department.value } })
    .then(r => data.value = r.data ?? {})
    .finally(() => loading.value = false)
}
onMounted(refresh)
</script>

<template>
  <Head title="Team Huddle" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Team Huddle</h1>
        <div class="flex items-center gap-2">
          <select v-model="department" @change="refresh" class="rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="d in DEPTS" :key="d" :value="d">{{ d.replace(/_/g, ' ') }}</option>
          </select>
          <a :href="`/huddle/pdf?department=${department}`" target="_blank" class="rounded bg-gray-600 px-3 py-1.5 text-sm text-white hover:bg-gray-700">
            Print PDF
          </a>
        </div>
      </div>

      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>

      <div v-else class="space-y-4">
        <div
          v-for="(items, section) in data"
          :key="section"
          class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4"
        >
          <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100 capitalize mb-2">
            {{ String(section).replace(/_/g, ' ') }}
          </h2>
          <div v-if="Array.isArray(items)">
            <ul class="space-y-1 text-sm">
              <li v-for="(it, i) in items" :key="i" class="border-b border-gray-100 dark:border-slate-700 pb-1">
                <template v-if="typeof it === 'object'">
                  <span class="text-gray-700 dark:text-slate-200">
                    {{ it.title ?? it.label ?? it.description ?? JSON.stringify(it) }}
                  </span>
                </template>
                <template v-else>{{ it }}</template>
              </li>
              <li v-if="!items.length" class="text-xs text-gray-500 dark:text-slate-400">-</li>
            </ul>
          </div>
          <div v-else class="text-sm text-gray-700 dark:text-slate-200">{{ items }}</div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
