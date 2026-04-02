// ─── NostosEMR Vue — Shared TypeScript Types ──────────────────────────────────
// Global type definitions shared across all Vue pages and components.
// Mirrors the Inertia shared props injected by HandleInertiaRequests.php.
// ─────────────────────────────────────────────────────────────────────────────

export interface User {
    id: number
    first_name: string
    last_name: string
    email: string
    department: string
    department_label: string
    role: string
    site_id: number | null
    site_name: string | null
    tenant_id: number
    tenant_name: string
    is_super_admin: boolean
    theme_preference: 'light' | 'dark'
    designations: string[]
    nav_groups: NavGroup[]
}

export interface RealUser {
    id: number
    first_name: string
    last_name: string
    email: string
}

export interface ImpersonationState {
    active: boolean
    user: ImpersonationUser | null
    viewing_as_dept: string | null
}

export interface ImpersonationUser {
    id: number
    first_name: string
    last_name: string
    department: string
    department_label: string
    role: string
}

export interface NavGroup {
    label: string
    items: NavItem[]
}

export interface NavItem {
    label: string
    module: string
    href: string
    badge?: number | null
}

export interface PageProps extends Record<string, unknown> {
    auth: {
        user: User
        real_user: RealUser | null
    }
    impersonation: ImpersonationState
    flash: {
        success?: string
        error?: string
    }
    ziggy: {
        url: string
        port: number | null
        defaults: Record<string, unknown>
        routes: Record<string, unknown>
    }
}

// Axios global augment
import axios from 'axios'
declare global {
    interface Window {
        axios: typeof axios
        Echo: import('laravel-echo').default
        Pusher: typeof import('pusher-js').default
    }
}
