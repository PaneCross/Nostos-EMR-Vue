<script setup lang="ts">
// ─── AdvanceDirective/Wizard ────────────────────────────────────────────────
// Step-through wizard for capturing a participant's advance directives:
// DNR (Do Not Resuscitate), DPOA (Durable Power of Attorney for healthcare),
// living will preferences, and POLST/MOLST physician orders.
//
// Audience: Social Work primary; clinicians can review.
//
// Notable rules:
//   - Patient Self-Determination Act + 42 CFR §460.156: programs must
//     ask + document advance-directive status at enrollment and on change.
//   - Documents are e-signed (ESIGN/UETA-compliant capture); historical
//     versions are retained (append-only: supersession, not deletion).
//   - Periodic AD review job nudges clinicians when a directive ages out.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const props = defineProps<{ participant: any }>()

const step = ref(1)
const saving = ref(false)
const error = ref<string | null>(null)

const AD_TYPES = [
  { value: 'dnr', label: 'DNR: Do Not Resuscitate' },
  { value: 'polst', label: 'POLST' },
  { value: 'molst', label: 'MOLST' },
  { value: 'healthcare_proxy', label: 'Healthcare Proxy' },
  { value: 'living_will', label: 'Living Will' },
  { value: 'combined', label: 'Combined / Full AD' },
]

const form = ref({
  ad_type: 'dnr',
  choices: {
    code_status: 'full_code',
    intubation: 'allow',
    artificial_nutrition: 'allow',
    antibiotics: 'allow',
    comfort_only: false,
  } as Record<string, any>,
  representative_type: 'self',
  proxy_signer_name: '',
  proxy_relationship: '',
})

// Signature canvas
const canvas = ref<HTMLCanvasElement | null>(null)
let ctx: CanvasRenderingContext2D | null = null
let drawing = false
const hasSignature = ref(false)

function initCanvas() {
  const c = canvas.value
  if (!c) return
  ctx = c.getContext('2d')
  if (!ctx) return
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, c.width, c.height)
  ctx.strokeStyle = '#000'
  ctx.lineWidth = 2
  ctx.lineCap = 'round'
}

function posFromEvent(e: MouseEvent | TouchEvent): { x: number; y: number } {
  const c = canvas.value!
  const r = c.getBoundingClientRect()
  const p = 'touches' in e ? e.touches[0] : e
  return { x: (p.clientX - r.left) * (c.width / r.width), y: (p.clientY - r.top) * (c.height / r.height) }
}

function start(e: MouseEvent | TouchEvent) {
  e.preventDefault()
  drawing = true
  const { x, y } = posFromEvent(e)
  ctx!.beginPath()
  ctx!.moveTo(x, y)
}
function move(e: MouseEvent | TouchEvent) {
  if (!drawing) return
  e.preventDefault()
  const { x, y } = posFromEvent(e)
  ctx!.lineTo(x, y)
  ctx!.stroke()
  hasSignature.value = true
}
function end() { drawing = false }
function clearSig() {
  if (!canvas.value || !ctx) return
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.value.width, canvas.value.height)
  hasSignature.value = false
}

onMounted(() => {
  initCanvas()
  const c = canvas.value
  if (!c) return
  c.addEventListener('mousedown', start)
  c.addEventListener('mousemove', move)
  c.addEventListener('mouseup', end)
  c.addEventListener('mouseleave', end)
  c.addEventListener('touchstart', start, { passive: false })
  c.addEventListener('touchmove', move, { passive: false })
  c.addEventListener('touchend', end)
})
onBeforeUnmount(() => {
  const c = canvas.value
  if (!c) return
  c.removeEventListener('mousedown', start)
  c.removeEventListener('mousemove', move)
  c.removeEventListener('mouseup', end)
  c.removeEventListener('mouseleave', end)
})

const canNext = computed(() => {
  if (step.value === 4) return hasSignature.value
  return true
})

async function submit() {
  saving.value = true; error.value = null
  try {
    const dataUrl = canvas.value!.toDataURL('image/png')
    const payload: any = {
      ad_type: form.value.ad_type,
      choices: form.value.choices,
      signature_data_url: dataUrl,
      representative_type: form.value.representative_type,
    }
    if (form.value.proxy_signer_name) {
      payload.proxy_signer_name = form.value.proxy_signer_name
      payload.proxy_relationship = form.value.proxy_relationship
    }
    await axios.post(`/participants/${props.participant.id}/advance-directive`, payload)
    router.visit(`/participants/${props.participant.id}?tab=consents`)
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Save failed'
  } finally { saving.value = false }
}
</script>

<template>
  <Head title="Advance Directive" />
  <AppShell>
    <div class="max-w-3xl mx-auto p-6 space-y-6">
      <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Advance Directive Wizard</h1>
        <p class="text-sm text-gray-500 dark:text-slate-400">
          {{ participant?.first_name }} {{ participant?.last_name }}: MRN {{ participant?.mrn }}
        </p>
      </div>

      <!-- Stepper -->
      <div class="flex gap-1 text-xs">
        <div v-for="s in [1,2,3,4]" :key="s"
             :class="['flex-1 text-center rounded py-1.5', step === s ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300']">
          Step {{ s }}
        </div>
      </div>

      <div class="rounded-xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 p-6 space-y-4">
        <!-- Step 1: type -->
        <div v-if="step === 1">
          <h2 class="text-sm font-semibold mb-3">Type of advance directive</h2>
          <div class="space-y-2">
            <label v-for="t in AD_TYPES" :key="t.value" class="flex items-center gap-2 text-sm">
              <input type="radio" :value="t.value" v-model="form.ad_type" />
              <span>{{ t.label }}</span>
            </label>
          </div>
        </div>

        <!-- Step 2: choices -->
        <div v-if="step === 2" class="space-y-3">
          <h2 class="text-sm font-semibold">Choices</h2>
          <div>
            <label class="block text-xs mb-1">Code status</label>
            <select v-model="form.choices.code_status" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="full_code">Full code</option>
              <option value="dnr">DNR (Do Not Resuscitate)</option>
              <option value="dni">DNI (Do Not Intubate)</option>
              <option value="dnr_dni">DNR + DNI</option>
            </select>
          </div>
          <div>
            <label class="block text-xs mb-1">Intubation</label>
            <select v-model="form.choices.intubation" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="allow">Allow</option>
              <option value="decline">Decline</option>
            </select>
          </div>
          <div>
            <label class="block text-xs mb-1">Artificial nutrition</label>
            <select v-model="form.choices.artificial_nutrition" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="allow">Allow</option>
              <option value="trial">Trial period only</option>
              <option value="decline">Decline</option>
            </select>
          </div>
          <div>
            <label class="block text-xs mb-1">Antibiotics</label>
            <select v-model="form.choices.antibiotics" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="allow">Allow</option>
              <option value="comfort_only">Comfort only</option>
              <option value="decline">Decline</option>
            </select>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="form.choices.comfort_only" />
            <span>Comfort care only</span>
          </label>
        </div>

        <!-- Step 3: review -->
        <div v-if="step === 3">
          <h2 class="text-sm font-semibold mb-3">Review</h2>
          <dl class="text-sm space-y-1">
            <div class="flex justify-between"><dt>Type</dt><dd class="font-semibold">{{ form.ad_type }}</dd></div>
            <div v-for="(v, k) in form.choices" :key="k" class="flex justify-between border-b border-slate-100 dark:border-slate-700 py-1">
              <dt class="text-slate-600 dark:text-slate-400">{{ String(k).replace(/_/g, ' ') }}</dt>
              <dd class="font-medium">{{ v }}</dd>
            </div>
          </dl>
          <div class="mt-4 space-y-2">
            <label class="block text-xs">Signing as</label>
            <select v-model="form.representative_type" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="self">Self</option>
              <option value="guardian">Guardian</option>
              <option value="poa">POA</option>
              <option value="healthcare_proxy">Healthcare proxy</option>
            </select>
            <template v-if="form.representative_type !== 'self'">
              <input v-model="form.proxy_signer_name" placeholder="Proxy name" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
              <input v-model="form.proxy_relationship" placeholder="Proxy relationship (e.g. Daughter (POA))" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
            </template>
          </div>
        </div>

        <!-- Step 4: signature -->
        <div v-if="step === 4">
          <h2 class="text-sm font-semibold mb-3">Signature</h2>
          <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">
            Sign below. By signing, the signer confirms the choices above. This signature is captured under ESIGN/UETA.
          </p>
          <canvas ref="canvas" width="600" height="200" class="border border-slate-300 dark:border-slate-600 rounded bg-white w-full touch-none"></canvas>
          <div class="mt-2 flex gap-2">
            <button class="text-xs text-slate-600 hover:underline dark:text-slate-300" @click="clearSig">Clear</button>
          </div>
        </div>

        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

        <div class="flex justify-between pt-4 border-t border-slate-200 dark:border-slate-700">
          <button :disabled="step === 1" class="rounded bg-slate-300 dark:bg-slate-600 text-slate-800 dark:text-slate-100 px-3 py-1.5 text-sm disabled:opacity-40" @click="step--">Back</button>
          <button
            v-if="step < 4"
            :disabled="!canNext"
            class="rounded bg-blue-600 text-white px-3 py-1.5 text-sm hover:bg-blue-700 disabled:opacity-50"
            @click="step++"
          >Next</button>
          <button
            v-else
            :disabled="!canNext || saving"
            class="rounded bg-green-600 text-white px-3 py-1.5 text-sm hover:bg-green-700 disabled:opacity-50"
            @click="submit"
          >{{ saving ? 'Saving…' : 'Sign & save' }}</button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
