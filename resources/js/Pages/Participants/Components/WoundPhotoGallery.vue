<script setup lang="ts">
// ─── WoundPhotoGallery.vue: Phase J5 ────────────────────────────────────────
// Per-wound photo gallery. GET /wounds/{wound}/photos, POST to attach.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps<{ wound: { id: number } }>()

const loading = ref(true)
const photos = ref<any[]>([])
const showAttachForm = ref(false)
const saving = ref(false)
const form = ref({
  document_id: '' as number | string,
  taken_at: new Date().toISOString().slice(0, 10),
  notes: '',
})
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  axios.get(`/wounds/${props.wound.id}/photos`)
    .then(r => photos.value = r.data.photos ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function submit() {
  saving.value = true
  error.value = null
  try {
    const p: any = { taken_at: form.value.taken_at }
    if (form.value.document_id) p.document_id = Number(form.value.document_id)
    if (form.value.notes) p.notes = form.value.notes
    await axios.post(`/wounds/${props.wound.id}/photos`, p)
    showAttachForm.value = false
    form.value.document_id = ''
    form.value.notes = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally { saving.value = false }
}
</script>

<template>
  <div class="border-t border-gray-200 dark:border-slate-700 px-4 py-2 text-xs">
    <div class="flex items-center gap-2">
      <span class="font-semibold text-gray-700 dark:text-slate-300">
        Photos ({{ photos.length }})
      </span>
      <button
        class="text-blue-600 dark:text-blue-400 hover:underline"
        @click="showAttachForm = !showAttachForm"
      >
        {{ showAttachForm ? 'Cancel' : '+ attach' }}
      </button>
    </div>

    <div v-if="showAttachForm" class="mt-2 flex flex-wrap items-end gap-2">
      <input
        type="number"
        v-model="form.document_id"
        placeholder="Document ID (optional)"
        class="rounded border-gray-300 dark:border-slate-600 text-xs"
      />
      <input type="date" v-model="form.taken_at" class="rounded border-gray-300 dark:border-slate-600 text-xs" />
      <input v-model="form.notes" placeholder="Notes" class="rounded border-gray-300 dark:border-slate-600 text-xs" />
      <button :disabled="saving" class="rounded bg-blue-600 px-2 py-1 text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
        {{ saving ? '…' : 'Attach' }}
      </button>
      <span v-if="error" class="text-red-600 dark:text-red-400">{{ error }}</span>
    </div>

    <ul v-if="photos.length" class="mt-2 space-y-0.5 text-gray-600 dark:text-slate-400">
      <li v-for="p in photos" :key="p.id">
        · {{ p.taken_at }}: document #{{ p.document_id ?? '-' }}
        <span v-if="p.notes">· {{ p.notes }}</span>
      </li>
    </ul>
  </div>
</template>
