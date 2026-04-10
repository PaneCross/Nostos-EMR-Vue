<script setup lang="ts">
// ─── DocumentsTab.vue ─────────────────────────────────────────────────────────
// Document management. Lazy-loads via onMounted. Category filter.
// Upload modal (file + category + description). Stream download via controller.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { DocumentArrowUpIcon, ArrowDownTrayIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'

interface ParticipantDocument {
  id: number
  file_name: string
  file_type: string
  file_size: string
  file_size_bytes: number | null
  description: string | null
  document_category: string
  category_label: string
  uploaded_by: string
  uploaded_at: string
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const CATEGORY_LABELS: Record<string, string> = {
  consent:           'Consent Forms',
  advance_directive: 'Advance Directives',
  insurance:         'Insurance Documents',
  legal:             'Legal Documents',
  referral:          'Referrals',
  lab:               'Lab Reports',
  imaging:           'Imaging / Radiology',
  clinical:          'Clinical Records',
  assessment:        'Assessments',
  care_plan:         'Care Plans',
  correspondence:    'Correspondence',
  other:             'Other',
}

const documents = ref<ParticipantDocument[]>([])
const loading = ref(false)
const loadError = ref('')
const filterCategory = ref('')
const showUploadModal = ref(false)
const uploading = ref(false)
const uploadError = ref('')

const uploadForm = ref({ category: 'clinical', description: '' })
const selectedFile = ref<File | null>(null)

onMounted(async () => {
  loading.value = true
  try {
    const res = await axios.get(`/participants/${props.participant.id}/documents`)
    documents.value = res.data.data ?? []
  } catch {
    loadError.value = 'Failed to load documents.'
  } finally {
    loading.value = false
  }
})

const filteredDocuments = computed(() =>
  filterCategory.value
    ? documents.value.filter(d => d.document_category === filterCategory.value)
    : documents.value
)

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function handleFileSelect(event: Event) {
  selectedFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function uploadDocument() {
  if (!selectedFile.value) { uploadError.value = 'Please select a file.'; return }
  uploading.value = true; uploadError.value = ''
  const formData = new FormData()
  formData.append('file', selectedFile.value)
  formData.append('document_category', uploadForm.value.category)
  if (uploadForm.value.description) formData.append('description', uploadForm.value.description)
  try {
    const res = await axios.post(`/participants/${props.participant.id}/documents`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    documents.value.unshift(res.data.document)
    showUploadModal.value = false
    uploadForm.value = { category: 'clinical', description: '' }
    selectedFile.value = null
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    uploadError.value = e.response?.data?.message ?? 'Upload failed.'
    uploading.value = false
  }
}

function downloadDocument(doc: ParticipantDocument) {
  // Controller streams the file — navigate directly to the download route
  window.open(`/participants/${props.participant.id}/documents/${doc.id}/download`, '_blank')
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4 gap-2 flex-wrap">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Documents</h2>
      <div class="flex items-center gap-2">
        <select name="filterCategory" v-model="filterCategory" class="text-xs border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200">
          <option value="">All categories</option>
          <option v-for="(label, key) in CATEGORY_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showUploadModal = true"
        >
          <DocumentArrowUpIcon class="w-3.5 h-3.5" />
          Upload
        </button>
      </div>
    </div>

    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">Loading documents...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 text-sm">{{ loadError }}</div>
    <div v-else-if="filteredDocuments.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No documents found.</div>
    <div v-else class="space-y-1.5">
      <div
        v-for="doc in filteredDocuments"
        :key="doc.id"
        class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-2.5"
      >
        <DocumentTextIcon class="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-900 dark:text-slate-100 truncate">{{ doc.file_name }}</span>
            <span class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-1.5 py-0.5 rounded shrink-0">
              {{ doc.category_label || CATEGORY_LABELS[doc.document_category] || doc.document_category }}
            </span>
          </div>
          <div class="text-xs text-gray-400 dark:text-slate-500 flex gap-2 flex-wrap mt-0.5">
            <span>{{ doc.file_size }}</span>
            <span>{{ fmtDate(doc.uploaded_at) }}</span>
            <span v-if="doc.uploaded_by">{{ doc.uploaded_by }}</span>
            <span v-if="doc.description">{{ doc.description }}</span>
          </div>
        </div>
        <button
          class="inline-flex items-center gap-1 text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors shrink-0"
          @click="downloadDocument(doc)"
        >
          <ArrowDownTrayIcon class="w-3 h-3" />
          Download
        </button>
      </div>
    </div>

    <!-- Upload modal -->
    <div v-if="showUploadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-sm w-full p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Upload Document</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">File *</label>
            <input type="file" class="w-full text-xs text-gray-700 dark:text-slate-300" @change="handleFileSelect" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Category</label>
            <select name="category" v-model="uploadForm.category" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200">
              <option v-for="(label, key) in CATEGORY_LABELS" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Description</label>
            <input v-model="uploadForm.description" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200" />
          </div>
        </div>
        <p v-if="uploadError" class="text-red-600 dark:text-red-400 text-xs mt-2">{{ uploadError }}</p>
        <div class="flex gap-2 mt-4">
          <button :disabled="uploading" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="uploadDocument">
            {{ uploading ? 'Uploading...' : 'Upload' }}
          </button>
          <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showUploadModal = false; uploadError = ''">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
