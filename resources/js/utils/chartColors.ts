// ─── chartColors.ts ───────────────────────────────────────────────────────────
// Shared color palette presets for all dashboard charts.
// Import the scheme name from each chart component's colorScheme prop.
// ─────────────────────────────────────────────────────────────────────────────

export type ColorScheme = 'default' | 'clinical' | 'finance'

export interface ChartPalette {
    backgrounds: string[]
    borders: string[]
    gridColor: string
}

// Indigo/slate — general purpose UI
const defaultPalette: ChartPalette = {
    backgrounds: [
        'rgba(99, 102, 241, 0.7)', // indigo-500
        'rgba(148, 163, 184, 0.7)', // slate-400
        'rgba(129, 140, 248, 0.7)', // indigo-400
        'rgba(71, 85, 105, 0.7)', // slate-600
        'rgba(165, 180, 252, 0.7)', // indigo-300
        'rgba(100, 116, 139, 0.7)', // slate-500
    ],
    borders: [
        'rgb(99, 102, 241)',
        'rgb(148, 163, 184)',
        'rgb(129, 140, 248)',
        'rgb(71, 85, 105)',
        'rgb(165, 180, 252)',
        'rgb(100, 116, 139)',
    ],
    gridColor: 'rgba(148, 163, 184, 0.2)',
}

// Teal/emerald — clinical data (vitals, medications, care plans)
const clinicalPalette: ChartPalette = {
    backgrounds: [
        'rgba(20, 184, 166, 0.7)', // teal-500
        'rgba(52, 211, 153, 0.7)', // emerald-400
        'rgba(45, 212, 191, 0.7)', // teal-400
        'rgba(16, 185, 129, 0.7)', // emerald-500
        'rgba(94, 234, 212, 0.7)', // teal-300
        'rgba(110, 231, 183, 0.7)', // emerald-300
    ],
    borders: [
        'rgb(20, 184, 166)',
        'rgb(52, 211, 153)',
        'rgb(45, 212, 191)',
        'rgb(16, 185, 129)',
        'rgb(94, 234, 212)',
        'rgb(110, 231, 183)',
    ],
    gridColor: 'rgba(20, 184, 166, 0.15)',
}

// Violet/indigo — financial data (capitation, encounters, billing)
const financePalette: ChartPalette = {
    backgrounds: [
        'rgba(139, 92, 246, 0.7)', // violet-500
        'rgba(99, 102, 241, 0.7)', // indigo-500
        'rgba(167, 139, 250, 0.7)', // violet-400
        'rgba(129, 140, 248, 0.7)', // indigo-400
        'rgba(196, 181, 253, 0.7)', // violet-300
        'rgba(165, 180, 252, 0.7)', // indigo-300
    ],
    borders: [
        'rgb(139, 92, 246)',
        'rgb(99, 102, 241)',
        'rgb(167, 139, 250)',
        'rgb(129, 140, 248)',
        'rgb(196, 181, 253)',
        'rgb(165, 180, 252)',
    ],
    gridColor: 'rgba(139, 92, 246, 0.15)',
}

export const chartPalettes: Record<ColorScheme, ChartPalette> = {
    default: defaultPalette,
    clinical: clinicalPalette,
    finance: financePalette,
}
