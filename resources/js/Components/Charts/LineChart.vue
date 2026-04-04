<script setup lang="ts">
// ─── LineChart.vue ─────────────────────────────────────────────────────────────
// Reusable line chart for time-series dashboard data (vitals trends, encounter
// volume over time, etc.). Wraps Chart.js via vue-chartjs.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
} from 'chart.js'
import type { ChartData, ChartOptions } from 'chart.js'
import { chartPalettes } from '@/utils/chartColors'
import type { ColorScheme } from '@/utils/chartColors'

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler)

const props = withDefaults(
    defineProps<{
        data: ChartData<'line'>
        labels: string[]
        title?: string
        height?: number
        colorScheme?: ColorScheme
        /** Fill the area under the line — useful for single-series trend charts */
        fill?: boolean
    }>(),
    {
        title: undefined,
        height: 200,
        colorScheme: 'default',
        fill: false,
    },
)

const palette = computed(() => chartPalettes[props.colorScheme])

const chartData = computed<ChartData<'line'>>(() => ({
    labels: props.labels,
    datasets: props.data.datasets.map((ds, i) => ({
        ...ds,
        borderColor: ds.borderColor ?? palette.value.borders[i % palette.value.borders.length],
        backgroundColor: ds.backgroundColor ?? palette.value.backgrounds[i % palette.value.backgrounds.length],
        tension: 0.3,
        fill: props.fill,
        pointRadius: 3,
    })),
}))

const options = computed<ChartOptions<'line'>>(() => ({
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
    <div :style="{ height: `${height}px` }" role="img" :aria-label="title ?? 'Line chart'">
        <Line :data="chartData" :options="options" />
        <div class="sr-only">
            <slot name="sr-description">
                {{ title ? `${title}: ` : '' }}Chart data table not available.
            </slot>
        </div>
    </div>
</template>
