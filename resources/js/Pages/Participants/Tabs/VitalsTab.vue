<script setup lang="ts">
// ─── VitalsTab.vue ────────────────────────────────────────────────────────────
// Vitals history table with interactive SVG trend chart (no external deps).
// Column headers are clickable to switch the active chart view. Out-of-range
// values highlighted: BP systolic > 180 → red, O2 < 92 → red. BMI color-coded
// by clinical range. Transfer dates shown as amber dashed reference lines.
// Append-only — no edit/delete. Last 30 readings used for chart; last 20 for table.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Vital {
  id: number; recorded_at: string
  bp_systolic: number | null; bp_diastolic: number | null
  pulse: number | null; temperature_f: number | null
  respiratory_rate: number | null; o2_saturation: number | null
  weight_lbs: number | null; height_in: number | null
  pain_score: number | null; blood_glucose: number | null
  blood_glucose_timing: string | null; bmi: number | null; notes: string | null
  recorded_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

interface CompletedTransfer {
  effective_date: string
  from_site_name: string | null
  to_site_name: string | null
}

const props = defineProps<{
  participant: Participant
  vitals: Vital[]
  completedTransfers?: CompletedTransfer[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────

const vitals      = ref<Vital[]>(props.vitals)
const showAddForm = ref(false)
const saving      = ref(false)
const error       = ref('')
const activeChart = ref('bp')

// ── Chart configurations ───────────────────────────────────────────────────────

type ChartConfig = {
  label: string
  lines: { key: string; color: string; name: string }[]
  unit: string
  domain?: [number, number]
}

const CHART_CONFIGS: Record<string, ChartConfig> = {
  bp:               { label: 'Blood Pressure',  lines: [{ key: 'sys', color: '#dc2626', name: 'Systolic' }, { key: 'dia', color: '#ea580c', name: 'Diastolic' }], unit: 'mmHg', domain: [40, 220] },
  pulse:            { label: 'Pulse',           lines: [{ key: 'pulse', color: '#2563eb', name: 'Pulse' }],             unit: 'bpm',  domain: [30, 160] },
  temperature_f:    { label: 'Temperature',     lines: [{ key: 'temperature_f', color: '#d97706', name: 'Temp' }],     unit: '\u00b0F',  domain: [94, 106] },
  respiratory_rate: { label: 'Resp Rate',       lines: [{ key: 'respiratory_rate', color: '#059669', name: 'RR' }],    unit: '/min', domain: [8, 40] },
  o2_saturation:    { label: 'O\u2082 Sat',     lines: [{ key: 'o2_saturation', color: '#7c3aed', name: 'O\u2082 Sat' }],  unit: '%', domain: [80, 100] },
  weight_lbs:       { label: 'Weight',          lines: [{ key: 'weight_lbs', color: '#64748b', name: 'Weight' }],      unit: 'lbs' },
  pain_score:       { label: 'Pain Score',      lines: [{ key: 'pain_score', color: '#be123c', name: 'Pain' }],        unit: '/10',  domain: [0, 10] },
  blood_glucose:    { label: 'Blood Glucose',   lines: [{ key: 'blood_glucose', color: '#0891b2', name: 'Glucose' }],  unit: 'mg/dL', domain: [40, 400] },
  bmi:              { label: 'BMI',             lines: [{ key: 'bmi', color: '#16a34a', name: 'BMI' }],                unit: '', domain: [10, 50] },
}

const TABLE_HEADERS: { label: string; chartKey?: string }[] = [
  { label: 'Date / Time' },
  { label: 'BP',      chartKey: 'bp' },
  { label: 'Pulse',   chartKey: 'pulse' },
  { label: 'Temp',    chartKey: 'temperature_f' },
  { label: 'RR',      chartKey: 'respiratory_rate' },
  { label: 'O\u2082%', chartKey: 'o2_saturation' },
  { label: 'Weight',  chartKey: 'weight_lbs' },
  { label: 'BMI',     chartKey: 'bmi' },
  { label: 'Glucose', chartKey: 'blood_glucose' },
  { label: 'Pain',    chartKey: 'pain_score' },
]

// ── Responsive chart dimensions ────────────────────────────────────────────────
// ResizeObserver measures the container in real pixels so the SVG is drawn at
// 1:1 pixel scale — no viewBox distortion, no text stretching.

const containerEl = ref<HTMLDivElement | null>(null)
const svgEl       = ref<SVGSVGElement | null>(null)
const svgWidth    = ref(760)

const SVG_H   = 180
const M       = { top: 10, right: 14, bottom: 30, left: 44 }
const CHART_H = SVG_H - M.top - M.bottom  // 140 — constant

// Reactive horizontal drawing area
const chartAreaW = computed(() => Math.max(200, svgWidth.value - M.left - M.right))

// ── Dark mode detection ────────────────────────────────────────────────────────

const isDark = ref(false)
let themeObserver: MutationObserver | null = null
let resizeObs: ResizeObserver | null = null

onMounted(() => {
  isDark.value = document.documentElement.classList.contains('dark')
  themeObserver = new MutationObserver(() => {
    isDark.value = document.documentElement.classList.contains('dark')
  })
  themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

  if (containerEl.value) {
    svgWidth.value = Math.floor(containerEl.value.clientWidth)
    resizeObs = new ResizeObserver(entries => {
      svgWidth.value = Math.floor(entries[0].contentRect.width)
    })
    resizeObs.observe(containerEl.value)
  }
})

onUnmounted(() => {
  themeObserver?.disconnect()
  resizeObs?.disconnect()
})

const svgGridColor   = computed(() => isDark.value ? '#334155' : '#e5e7eb')
const svgAxisColor   = computed(() => isDark.value ? '#94a3b8' : '#6b7280')
const svgBorderColor = computed(() => isDark.value ? '#334155' : '#e5e7eb')

// ── Chart data ─────────────────────────────────────────────────────────────────

type ChartPoint = {
  date: string
  sys: number | null; dia: number | null
  pulse: number | null; temperature_f: number | null
  respiratory_rate: number | null; o2_saturation: number | null
  weight_lbs: number | null; bmi: number | null
  pain_score: number | null; blood_glucose: number | null
}

const n = (v: number | string | null): number | null =>
  v === null || v === '' ? null : Number(v)

const chartData = computed((): ChartPoint[] =>
  [...vitals.value]
    .slice(0, 30)
    .reverse()
    .map(v => ({
      date:             new Date(v.recorded_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
      sys:              n(v.bp_systolic),
      dia:              n(v.bp_diastolic),
      pulse:            n(v.pulse),
      temperature_f:    n(v.temperature_f),
      respiratory_rate: n(v.respiratory_rate),
      o2_saturation:    n(v.o2_saturation),
      weight_lbs:       n(v.weight_lbs),
      bmi:              n(v.bmi),
      pain_score:       n(v.pain_score),
      blood_glucose:    n(v.blood_glucose),
    }))
)

const currentConfig = computed(() => CHART_CONFIGS[activeChart.value])

const hasChartData = computed(() => {
  const pk = currentConfig.value.lines[0].key
  return chartData.value.filter(d => (d as Record<string, unknown>)[pk] !== null).length > 1
})

// ── SVG geometry ───────────────────────────────────────────────────────────────

function xPos(i: number): number {
  const N = chartData.value.length
  if (N <= 1) return M.left + chartAreaW.value / 2
  return M.left + (i / (N - 1)) * chartAreaW.value
}

const effectiveDomain = computed((): [number, number] => {
  const cfg = currentConfig.value
  if (cfg.domain) return cfg.domain
  const vals = cfg.lines
    .flatMap(l => chartData.value.map(d => (d as Record<string, unknown>)[l.key] as number | null))
    .filter((v): v is number => v !== null)
  if (vals.length === 0) return [0, 100]
  const min = Math.min(...vals)
  const max = Math.max(...vals)
  const pad = (max - min) * 0.12 || 8
  return [Math.floor(min - pad), Math.ceil(max + pad)]
})

function yPos(v: number): number {
  const [dmin, dmax] = effectiveDomain.value
  const clamped = Math.max(dmin, Math.min(dmax, v))
  return M.top + CHART_H - ((clamped - dmin) / (dmax - dmin)) * CHART_H
}

const yTicks = computed(() => {
  const [dmin, dmax] = effectiveDomain.value
  return Array.from({ length: 5 }, (_, i) => {
    const v = dmin + (i / 4) * (dmax - dmin)
    const rounded = Math.round(v * 10) / 10
    return { v: rounded, y: yPos(v), label: rounded.toString() }
  })
})

const xLabels = computed(() => {
  const N = chartData.value.length
  if (N === 0) return []
  const step = Math.max(1, Math.ceil(N / 7))
  return chartData.value
    .map((d, i) => ({ label: d.date, x: xPos(i), i }))
    .filter((_, i, arr) => i % step === 0 || i === arr.length - 1)
})

// Build a smooth SVG path using Catmull-Rom → cubic Bezier conversion.
// Control points: C1 = P1 + (P2 - P0)/6, C2 = P2 - (P3 - P1)/6
// At endpoints P[-1] = P[0] and P[N] = P[N-1] (clamped), producing a gentle
// start and end that matches Recharts' default monotone curve appearance.
// Null values split the line into separate sub-paths (M restarts the stroke).
function buildPath(key: string): string {
  // Collect runs of consecutive non-null points
  type Pt = { x: number; y: number }
  const runs: Pt[][] = []
  let run: Pt[] = []
  for (let i = 0; i < chartData.value.length; i++) {
    const v = (chartData.value[i] as Record<string, unknown>)[key] as number | null
    if (v === null) {
      if (run.length) { runs.push(run); run = [] }
    } else {
      run.push({ x: xPos(i), y: yPos(v) })
    }
  }
  if (run.length) runs.push(run)

  return runs.map(pts => {
    if (pts.length === 1) return `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`
    let d = `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[Math.max(0, i - 1)]
      const p1 = pts[i]
      const p2 = pts[i + 1]
      const p3 = pts[Math.min(pts.length - 1, i + 2)]
      const cp1x = (p1.x + (p2.x - p0.x) / 6).toFixed(1)
      const cp1y = (p1.y + (p2.y - p0.y) / 6).toFixed(1)
      const cp2x = (p2.x - (p3.x - p1.x) / 6).toFixed(1)
      const cp2y = (p2.y - (p3.y - p1.y) / 6).toFixed(1)
      d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x.toFixed(1)} ${p2.y.toFixed(1)}`
    }
    return d
  }).join(' ')
}

const transferPositions = computed(() =>
  (props.completedTransfers ?? [])
    .map(t => {
      const label = new Date(t.effective_date + 'T00:00:00')
        .toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
      const idx = chartData.value.findIndex(d => d.date === label)
      return idx === -1 ? null : { x: xPos(idx), to_site_name: t.to_site_name }
    })
    .filter((t): t is { x: number; to_site_name: string | null } => t !== null)
)

// ── Hover tooltip ──────────────────────────────────────────────────────────────

const hoverIdx = ref<number | null>(null)

function onSvgMouseMove(e: MouseEvent) {
  if (!svgEl.value || !hasChartData.value) return
  const rect = svgEl.value.getBoundingClientRect()
  // SVG is drawn at 1:1 pixel scale — no coordinate conversion needed
  const mouseX = e.clientX - rect.left
  const N = chartData.value.length
  if (N === 0) return
  let closest = 0; let minDist = Infinity
  for (let i = 0; i < N; i++) {
    const dist = Math.abs(mouseX - xPos(i))
    if (dist < minDist) { minDist = dist; closest = i }
  }
  hoverIdx.value = closest
}

function onSvgMouseLeave() {
  hoverIdx.value = null
}

const hoverPoint = computed(() =>
  hoverIdx.value !== null ? (chartData.value[hoverIdx.value] ?? null) : null
)

// Tooltip left position in pixels, clamped so it stays within chart bounds
const tooltipStylePx = computed(() => {
  if (hoverIdx.value === null) return {}
  const px   = xPos(hoverIdx.value)
  const half = 72  // half of min-w-[144px]
  const clamped = Math.max(half, Math.min(svgWidth.value - half, px))
  return { left: `${clamped}px`, top: '4px', transform: 'translateX(-50%)' }
})

function dotY(idx: number, key: string): number {
  const v = (chartData.value[idx] as Record<string, unknown>)[key] as number | null
  return v !== null ? yPos(v) : -999
}

function hoverValue(key: string): string {
  if (!hoverPoint.value) return '-'
  const v = (hoverPoint.value as Record<string, unknown>)[key]
  if (v === null || v === undefined) return '-'
  const num = Number(v)
  return isNaN(num) ? '-' : Number.isInteger(num) ? num.toString() : num.toFixed(1)
}

// ── Value coloring ─────────────────────────────────────────────────────────────

function highSys(v: number | null): boolean { return v !== null && v > 180 }
function lowO2(v: number | null): boolean   { return v !== null && v < 92 }

function bmiColor(bmi: number | null): string {
  if (bmi === null) return 'text-gray-500 dark:text-slate-400'
  if (bmi < 18.5)   return 'text-amber-600 dark:text-amber-400 font-semibold'
  if (bmi < 25)     return 'text-green-600 dark:text-green-400'
  if (bmi < 30)     return 'text-amber-600 dark:text-amber-400 font-semibold'
  return 'text-red-600 dark:text-red-400 font-semibold'
}

// ── Form helpers ───────────────────────────────────────────────────────────────

const GLUCOSE_TIMING_LABELS: Record<string, string> = {
  before_meal: 'Before Meal', after_meal: 'After Meal',
  fasting: 'Fasting', random: 'Random', bedtime: 'Bedtime',
}

const form = ref({
  recorded_at: new Date().toISOString().slice(0, 16),
  bp_systolic: '', bp_diastolic: '', pulse: '', temperature_f: '',
  respiratory_rate: '', o2_saturation: '', weight_lbs: '', height_in: '',
  pain_score: '', blood_glucose: '', blood_glucose_timing: '', notes: '',
})

function fmtDateTime(val: string): string {
  return new Date(val).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

async function submit() {
  saving.value = true; error.value = ''
  const payload: Record<string, unknown> = { recorded_at: form.value.recorded_at }
  const numFields = [
    'bp_systolic','bp_diastolic','pulse','temperature_f','respiratory_rate',
    'o2_saturation','weight_lbs','height_in','pain_score','blood_glucose',
  ] as const
  numFields.forEach(f => {
    const v = form.value[f]
    payload[f] = v !== '' ? parseFloat(v as string) : null
  })
  payload.blood_glucose_timing = form.value.blood_glucose_timing || null
  payload.notes = form.value.notes || null
  try {
    const res = await axios.post(`/participants/${props.participant.id}/vitals`, payload)
    vitals.value.unshift(res.data)
    showAddForm.value = false
    form.value = {
      recorded_at: new Date().toISOString().slice(0, 16),
      bp_systolic:'',bp_diastolic:'',pulse:'',temperature_f:'',
      respiratory_rate:'',o2_saturation:'',weight_lbs:'',height_in:'',
      pain_score:'',blood_glucose:'',blood_glucose_timing:'',notes:'',
    }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save vitals.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6 space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
        Vital Signs ({{ vitals.length }} records)
      </h3>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon v-if="!showAddForm" class="w-3 h-3" />
        {{ showAddForm ? 'Cancel' : 'Record Vitals' }}
      </button>
    </div>

    <!-- Record vitals form -->
    <form
      v-if="showAddForm"
      class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 grid grid-cols-2 sm:grid-cols-4 gap-3"
      @submit.prevent="submit"
    >
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">BP Systolic <span class="text-gray-400 dark:text-slate-500">(mmHg)</span></label>
        <input v-model="form.bp_systolic" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">BP Diastolic <span class="text-gray-400 dark:text-slate-500">(mmHg)</span></label>
        <input v-model="form.bp_diastolic" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Pulse <span class="text-gray-400 dark:text-slate-500">(bpm)</span></label>
        <input v-model="form.pulse" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Temp <span class="text-gray-400 dark:text-slate-500">(&deg;F)</span></label>
        <input v-model="form.temperature_f" type="number" step="0.1" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Resp Rate <span class="text-gray-400 dark:text-slate-500">(/min)</span></label>
        <input v-model="form.respiratory_rate" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">O&#x2082; Sat <span class="text-gray-400 dark:text-slate-500">(%)</span></label>
        <input v-model="form.o2_saturation" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Weight <span class="text-gray-400 dark:text-slate-500">(lbs)</span></label>
        <input v-model="form.weight_lbs" type="number" step="0.1" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Height <span class="text-gray-400 dark:text-slate-500">(in)</span></label>
        <input v-model="form.height_in" type="number" step="0.1" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Blood Glucose <span class="text-gray-400 dark:text-slate-500">(mg/dL)</span></label>
        <input v-model="form.blood_glucose" type="number" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Glucose Timing</label>
        <select v-model="form.blood_glucose_timing" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
          <option value="">-- select --</option>
          <option v-for="(label, key) in GLUCOSE_TIMING_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Pain Score <span class="text-gray-400 dark:text-slate-500">(0-10)</span></label>
        <input v-model="form.pain_score" type="number" min="0" max="10" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div>
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Recorded At</label>
        <input v-model="form.recorded_at" type="datetime-local" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <div class="col-span-2 sm:col-span-4">
        <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Notes (optional)</label>
        <input v-model="form.notes" type="text" class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
      </div>
      <p v-if="error" class="col-span-2 sm:col-span-4 text-red-600 dark:text-red-400 text-xs">{{ error }}</p>
      <div class="col-span-2 sm:col-span-4 flex justify-end gap-2">
        <button type="button" class="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 text-gray-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700" @click="showAddForm = false">Cancel</button>
        <button type="submit" :disabled="saving" class="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
          {{ saving ? 'Saving...' : 'Record Vitals' }}
        </button>
      </div>
    </form>

    <!-- ── SVG Trend Chart ─────────────────────────────────────────────────── -->
    <div
      v-if="chartData.length > 1"
      class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4"
    >
      <h4 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">
        {{ currentConfig.label }} Trend
        <span class="font-normal normal-case text-gray-400 dark:text-slate-500">
          (last 30 readings &middot; click column headers to switch)
        </span>
      </h4>

      <div v-if="!hasChartData" class="py-10 text-center text-sm text-gray-400 dark:text-slate-500">
        No {{ currentConfig.label }} data recorded yet.
      </div>

      <!--
        containerEl is measured by ResizeObserver so the SVG always has exact pixel
        dimensions. No viewBox scaling means font-size="10" always = 10px, and data
        point positions always align exactly with axis labels.
      -->
      <div v-else ref="containerEl" class="relative">
        <svg
          ref="svgEl"
          :width="svgWidth"
          :height="SVG_H"
          :viewBox="`0 0 ${svgWidth} ${SVG_H}`"
          class="block cursor-crosshair"
          style="overflow: visible"
          aria-hidden="true"
          @mousemove="onSvgMouseMove"
          @mouseleave="onSvgMouseLeave"
        >
          <!-- Horizontal grid lines + Y axis labels -->
          <g v-for="tick in yTicks" :key="tick.v">
            <line
              :x1="M.left" :x2="M.left + chartAreaW"
              :y1="tick.y" :y2="tick.y"
              :stroke="svgGridColor" stroke-width="1"
            />
            <text
              :x="M.left - 4" :y="tick.y + 4"
              text-anchor="end" font-size="10" :fill="svgAxisColor"
            >{{ tick.label }}</text>
          </g>

          <!-- X axis date labels -->
          <text
            v-for="lbl in xLabels"
            :key="lbl.i"
            :x="lbl.x" :y="SVG_H - 4"
            text-anchor="middle" font-size="10" :fill="svgAxisColor"
          >{{ lbl.label }}</text>

          <!-- Transfer reference lines (amber dashed vertical) -->
          <g v-for="(t, i) in transferPositions" :key="`xfer-${i}`">
            <line
              :x1="t.x" :x2="t.x"
              :y1="M.top" :y2="M.top + CHART_H"
              stroke="#f59e0b" stroke-width="1.5" stroke-dasharray="4 3"
            />
            <text :x="t.x + 3" :y="M.top + 10" font-size="9" fill="#f59e0b">
              {{ t.to_site_name ?? 'Transfer' }}
            </text>
          </g>

          <!--
            Data series lines. :key includes activeChart so Vue destroys+recreates
            the <path> on chart switch, restarting the CSS draw animation.
            pathLength="1" normalises path length to 1 so stroke-dasharray:1 always
            equals one full stroke regardless of the actual pixel path length.
          -->
          <path
            v-for="line in currentConfig.lines"
            :key="`${activeChart}-${line.key}`"
            :d="buildPath(line.key)"
            pathLength="1"
            fill="none"
            :stroke="line.color"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="vitals-line"
          />

          <!-- Hover: vertical cursor line -->
          <line
            v-if="hoverIdx !== null"
            :x1="xPos(hoverIdx)" :x2="xPos(hoverIdx)"
            :y1="M.top" :y2="M.top + CHART_H"
            :stroke="svgAxisColor" stroke-width="1" stroke-dasharray="3 2" opacity="0.5"
          />

          <!-- Hover: dots at each data series value -->
          <circle
            v-for="line in currentConfig.lines"
            v-show="hoverIdx !== null"
            :key="`dot-${line.key}`"
            :cx="hoverIdx !== null ? xPos(hoverIdx) : 0"
            :cy="hoverIdx !== null ? dotY(hoverIdx, line.key) : 0"
            r="4"
            :fill="line.color"
            stroke="white"
            stroke-width="2"
          />

          <!-- Chart border -->
          <rect
            :x="M.left" :y="M.top"
            :width="chartAreaW" :height="CHART_H"
            fill="none"
            :stroke="svgBorderColor"
            stroke-width="1"
          />
        </svg>

        <!-- Tooltip card — pixel-positioned to snap to nearest data point -->
        <div
          v-if="hoverIdx !== null && hoverPoint"
          class="absolute pointer-events-none z-10
                 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600
                 rounded shadow-lg px-3 py-2 text-xs min-w-[144px]"
          :style="tooltipStylePx"
        >
          <div class="font-semibold text-gray-700 dark:text-slate-200 mb-1.5 pb-1 border-b border-gray-100 dark:border-slate-700">
            {{ hoverPoint.date }}
          </div>
          <div
            v-for="line in currentConfig.lines"
            :key="`tip-${line.key}`"
            class="flex items-center gap-1.5 py-0.5"
          >
            <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ backgroundColor: line.color }" />
            <span class="text-gray-500 dark:text-slate-400">{{ line.name }}:</span>
            <span class="font-medium text-gray-800 dark:text-slate-100 ml-auto pl-2 tabular-nums">
              {{ hoverValue(line.key) }}{{ currentConfig.unit ? `\u00a0${currentConfig.unit}` : '' }}
            </span>
          </div>
        </div>
      </div>

      <!-- Legend -->
      <div class="flex flex-wrap gap-4 mt-2">
        <div v-for="line in currentConfig.lines" :key="line.key" class="flex items-center gap-1.5">
          <div class="w-4 h-0.5 rounded-full" :style="{ backgroundColor: line.color }" />
          <span class="text-xs text-gray-500 dark:text-slate-400">
            {{ line.name }}{{ currentConfig.unit ? ` (${currentConfig.unit})` : '' }}
          </span>
        </div>
      </div>
    </div>

    <!-- ── Vitals table ────────────────────────────────────────────────────── -->
    <div v-if="vitals.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">
      No vitals recorded.
    </div>

    <div v-else class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-x-auto">
      <table class="text-xs w-max min-w-full">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th v-for="h in TABLE_HEADERS" :key="h.label" class="px-3 py-2 text-left whitespace-nowrap">
              <button
                v-if="h.chartKey"
                :class="[
                  'text-xs font-semibold uppercase tracking-wide transition-colors',
                  activeChart === h.chartKey
                    ? 'text-blue-600 dark:text-blue-400 border-b-2 border-blue-500 pb-0.5'
                    : 'text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-slate-300',
                ]"
                @click="activeChart = h.chartKey!"
              >{{ h.label }}</button>
              <span v-else class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{{ h.label }}</span>
            </th>
            <th class="px-3 py-2 text-left">
              <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">By</span>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr
            v-for="vital in vitals.slice(0, 20)"
            :key="vital.id"
            class="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50"
          >
            <td class="px-3 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ fmtDateTime(vital.recorded_at) }}</td>
            <td :class="['px-3 py-2 text-xs font-mono whitespace-nowrap', highSys(vital.bp_systolic) ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-slate-300']">
              {{ vital.bp_systolic != null ? `${vital.bp_systolic}/${vital.bp_diastolic}` : '-' }}
            </td>
            <td class="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{{ vital.pulse ?? '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{{ vital.temperature_f != null ? Number(vital.temperature_f).toFixed(1) : '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{{ vital.respiratory_rate ?? '-' }}</td>
            <td :class="['px-3 py-2 text-xs', lowO2(vital.o2_saturation) ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-slate-300']">{{ vital.o2_saturation ?? '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{{ vital.weight_lbs ?? '-' }}</td>
            <td :class="['px-3 py-2 text-xs', bmiColor(vital.bmi)]">{{ vital.bmi != null ? Number(vital.bmi).toFixed(1) : '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700 dark:text-slate-300 whitespace-nowrap">
              {{ vital.blood_glucose ?? '-' }}
              <span v-if="vital.blood_glucose_timing" class="text-gray-400 dark:text-slate-500 ml-1">({{ GLUCOSE_TIMING_LABELS[vital.blood_glucose_timing] ?? vital.blood_glucose_timing }})</span>
            </td>
            <td :class="['px-3 py-2 text-xs', vital.pain_score != null && vital.pain_score >= 7 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-slate-300']">{{ vital.pain_score ?? '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
              {{ vital.recorded_by ? `${vital.recorded_by.first_name[0]}. ${vital.recorded_by.last_name}` : '-' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>
</template>

<style scoped>
/*
  pathLength="1" on each <path> normalises the path to length 1.
  stroke-dasharray:1 = one full dash covering the whole path.
  stroke-dashoffset:1 = path starts invisible; animates to 0 (fully drawn).
  The :key on each path includes activeChart, so Vue recreates the element
  on chart switch, restarting this animation each time.
*/
.vitals-line {
  stroke-dasharray: 1;
  stroke-dashoffset: 1;
  animation: vitalsLineDraw 0.7s ease-out forwards;
}

@keyframes vitalsLineDraw {
  to { stroke-dashoffset: 0; }
}
</style>
