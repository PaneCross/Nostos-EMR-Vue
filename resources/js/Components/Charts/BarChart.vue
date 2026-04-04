<script setup lang="ts">
// ─── BarChart.vue ──────────────────────────────────────────────────────────────
// Reusable vertical bar chart for dashboard widgets.
// Wraps Chart.js via vue-chartjs. Accepts pre-shaped datasets and applies
// one of three color palette presets so all charts share a consistent look.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    BarElement,
    CategoryScale,
    LinearScale,
} from 'chart.js'
import type { ChartData, ChartOptions } from 'chart.js'
import { chartPalettes } from '@/utils/chartColors'
import type { ColorScheme } from '@/utils/chartColors'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = withDefaults(
    defineProps<{
        data: ChartData<'bar'>
        labels: string[]
        title?: string
        height?: number
        colorScheme?: ColorScheme
    }>(),
    {
        title: undefined,
        height: 200,
        colorScheme: 'default',
    },
)

const palette = computed(() => chartPalettes[props.colorScheme])

// Apply the selected palette to each dataset that doesn't already have colors set
const chartData = computed<ChartData<'bar'>>(() => ({
    labels: props.labels,
    datasets: props.data.datasets.map((ds, i) => ({
        ...ds,
        backgroundColor:
            ds.backgroundColor ?? palette.value.backgrounds[i % palette.value.backgrounds.length],
        borderColor: ds.borderColor ?? palette.value.borders[i % palette.value.borders.length],
        borderWidth: 1,
    })),
}))

const options = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: props.data.datasets.length > 1 },
        title: { display: !!props.title, text: props.title },
    },
    scales: {
        x: { grid: { color: palette.value.gridColor } },
        y: { grid: { color: palette.value.gridColor }, beginAtZero: true },
    },
}))
</script>

<template>
    <div :style="{ height: `${height}px` }" role="img" :aria-label="title ?? 'Bar chart'">
        <Bar :data="chartData" :options="options" />
        <!-- Screen reader fallback — populate via the named slot when chart data is meaningful -->
        <div class="sr-only">
            <slot name="sr-description">
                {{ title ? `${title}: ` : '' }}Chart data table not available.
            </slot>
        </div>
    </div>
</template>
