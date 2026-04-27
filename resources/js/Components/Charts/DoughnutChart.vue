<script setup lang="ts">
// ─── DoughnutChart.vue ─────────────────────────────────────────────────────────
// Reusable doughnut chart for showing proportional breakdowns (enrollment status
// distribution, payer mix, incident type split, etc.). Wraps Chart.js via vue-chartjs.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import type { ChartData, ChartOptions } from 'chart.js'
import { chartPalettes } from '@/utils/chartColors'
import type { ColorScheme } from '@/utils/chartColors'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = withDefaults(
    defineProps<{
        data: ChartData<'doughnut'>
        labels: string[]
        title?: string
        height?: number
        colorScheme?: ColorScheme
        /** Thickness of the doughnut ring: 0 to 1, where 1 fills to a pie chart */
        cutout?: string
    }>(),
    {
        title: undefined,
        height: 200,
        colorScheme: 'default',
        cutout: '65%',
    },
)

const palette = computed(() => chartPalettes[props.colorScheme])

const chartData = computed<ChartData<'doughnut'>>(() => ({
    labels: props.labels,
    datasets: props.data.datasets.map((ds) => ({
        ...ds,
        backgroundColor: ds.backgroundColor ?? palette.value.backgrounds,
        borderColor: ds.borderColor ?? palette.value.borders,
        borderWidth: 2,
    })),
}))

const options = computed<ChartOptions<'doughnut'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: props.cutout,
    plugins: {
        legend: { display: true, position: 'right' },
        title: { display: !!props.title, text: props.title },
    },
}))
</script>

<template>
    <div :style="{ height: `${height}px` }" role="img" :aria-label="title ?? 'Doughnut chart'">
        <Doughnut :data="chartData" :options="options" />
        <div class="sr-only">
            <slot name="sr-description">
                {{ title ? `${title}: ` : '' }}Chart data table not available.
            </slot>
        </div>
    </div>
</template>
