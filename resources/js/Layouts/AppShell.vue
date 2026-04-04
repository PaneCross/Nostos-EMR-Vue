<script setup lang="ts">
// ─── AppShell.vue ─────────────────────────────────────────────────────────────
// Root layout for all authenticated NostosEMR pages.
// Sidebar: collapsible via hamburger, default expanded, per-user localStorage.
// Nav: group accordion (expanded) + flyout on hover (collapsed).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted, type Component } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import {
    BellIcon,
    MoonIcon,
    SunIcon,
    UserIcon,
    ArrowRightOnRectangleIcon,
    EyeIcon,
    ChatBubbleLeftRightIcon,
    Bars3Icon,
    HomeIcon,
    ChevronRightIcon,
    // Group-level icons (mapped from PermissionService icon strings)
    UsersIcon,
    ClipboardDocumentListIcon,
    UserGroupIcon,
    CalendarDaysIcon,
    TruckIcon,
    CurrencyDollarIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    // Item-level icons
    ClipboardDocumentCheckIcon,
    DocumentTextIcon,
    HeartIcon,
    BeakerIcon,
    DocumentCheckIcon,
    ExclamationTriangleIcon,
    ExclamationCircleIcon,
    BuildingOfficeIcon,
    ClockIcon,
    MapIcon,
    XCircleIcon,
    ListBulletIcon,
    BuildingStorefrontIcon,
    IdentificationIcon,
    AdjustmentsHorizontalIcon,
    PhoneIcon,
    QueueListIcon,
    BanknotesIcon,
    TableCellsIcon,
    CloudArrowUpIcon,
    ShieldCheckIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    LockClosedIcon,
    PresentationChartBarIcon,
    CpuChipIcon,
    QuestionMarkCircleIcon,
} from '@heroicons/vue/24/outline'
import type { PageProps } from '@/types'
import IdleWarningModal from '@/Components/IdleWarningModal.vue'

// ── Shared props ───────────────────────────────────────────────────────────────
const page = usePage<PageProps>()
const auth = computed(() => page.props.auth)
const user = computed(() => auth.value?.user ?? null)
const impersonation = computed(() => page.props.impersonation ?? { active: false, user: null, viewing_as_dept: null })

// ── Theme ──────────────────────────────────────────────────────────────────────
const theme = ref<'light' | 'dark'>((user.value?.theme_preference as 'light' | 'dark' | undefined) ?? 'light')

function applyTheme(t: 'light' | 'dark') {
    document.documentElement.classList.toggle('dark', t === 'dark')
    localStorage.setItem('nostos_theme', t)
}

function toggleTheme() {
    theme.value = theme.value === 'light' ? 'dark' : 'light'
    applyTheme(theme.value)
    axios.post('/user/theme', { theme: theme.value })
}

// ── Nav icon maps ──────────────────────────────────────────────────────────────
// Maps PermissionService group.icon strings → Heroicons
const groupIconMap: Record<string, Component> = {
    users:     UsersIcon,
    clipboard: ClipboardDocumentListIcon,
    team:      UserGroupIcon,
    calendar:  CalendarDaysIcon,
    truck:     TruckIcon,
    dollar:    CurrencyDollarIcon,
    chart:     ChartBarIcon,
    settings:  Cog6ToothIcon,
}

// Maps PermissionService item.module → Heroicons
const moduleIconMap: Record<string, Component> = {
    participants:          UsersIcon,
    enrollment:            ClipboardDocumentCheckIcon,
    clinical_notes:        DocumentTextIcon,
    vitals:                HeartIcon,
    assessments:           ClipboardDocumentListIcon,
    care_plans:            ClipboardDocumentListIcon,
    medications:           BeakerIcon,
    orders:                DocumentCheckIcon,
    idt_dashboard:         UserGroupIcon,
    idt_minutes:           DocumentTextIcon,
    sdr_tracker:           ExclamationTriangleIcon,
    appointments:          CalendarDaysIcon,
    day_center:            BuildingOfficeIcon,
    transport_dashboard:   TruckIcon,
    transport_scheduler:   ClockIcon,
    dispatch_map:          MapIcon,
    cancellations:         XCircleIcon,
    transport_addons:      ListBulletIcon,
    vehicles:              TruckIcon,
    vendors:               BuildingStorefrontIcon,
    transport_credentials: IdentificationIcon,
    broker_settings:       AdjustmentsHorizontalIcon,
    courtesy_calls:        PhoneIcon,
    billing:               CurrencyDollarIcon,
    encounters:            DocumentCheckIcon,
    edi_batches:           QueueListIcon,
    capitation:            BanknotesIcon,
    pde_records:           TableCellsIcon,
    hpms_submissions:      CloudArrowUpIcon,
    hos_m_surveys:         ClipboardDocumentListIcon,
    revenue_integrity:     ShieldCheckIcon,
    reports:               ChartBarIcon,
    audit_log:             MagnifyingGlassIcon,
    grievances:            ExclamationCircleIcon,
    qapi_projects:         ShieldCheckIcon,
    user_management:       UsersIcon,
    locations:             MapPinIcon,
    system_settings:       Cog6ToothIcon,
    security_compliance:   LockClosedIcon,
    chat:                  ChatBubbleLeftRightIcon,
    executive_overview:    PresentationChartBarIcon,
    tenant_management:     CpuChipIcon,
}

function groupIcon(iconKey: string): Component {
    return groupIconMap[iconKey] ?? QuestionMarkCircleIcon
}

function navIcon(module: string): Component {
    return moduleIconMap[module] ?? QuestionMarkCircleIcon
}

// ── Nav helpers ────────────────────────────────────────────────────────────────
const navGroups = computed(() => (page.props.nav_groups as any[]) ?? [])
const currentPath = computed(() => window.location.pathname)

function isActive(href: string): boolean {
    const all: string[] = navGroups.value.flatMap((g: any) => g.items.map((i: any) => i.href))
    const longer = all.filter((h) => h !== href && h.startsWith(href) && h.length > href.length)
    if (longer.some((h) => currentPath.value.startsWith(h))) return false
    return currentPath.value === href || currentPath.value.startsWith(href + '/')
}

function isGroupActive(group: any): boolean {
    return group.items.some((item: any) => isActive(item.href))
}

// Dashboard href: use the user's dept route if known, fall back to '/' redirect
const dashboardHref = computed(() => {
    const dept = user.value?.department
    return dept ? `/dashboard/${dept}` : '/'
})

function navigate(href: string) {
    router.visit(href)
    hoveredGroup.value = null
}

// ── Sidebar collapse ───────────────────────────────────────────────────────────
// Default: expanded. Persisted per-user in localStorage.
const collapsed = ref(false)

function sidebarKey(): string {
    return `nostos_sidebar_${user.value?.id ?? 'guest'}`
}

function toggleSidebar() {
    collapsed.value = !collapsed.value
    localStorage.setItem(sidebarKey(), collapsed.value ? 'collapsed' : 'expanded')
    if (collapsed.value) hoveredGroup.value = null
}

// ── Group accordion ────────────────────────────────────────────────────────────
// NOTE: Vue 3 ref<Set> does NOT trigger reactivity on .add()/.delete() —
//       use a plain string[] array which IS deeply reactive.
const expandedGroups = ref<string[]>([])

function toggleGroup(label: string) {
    const idx = expandedGroups.value.indexOf(label)
    if (idx >= 0) {
        expandedGroups.value.splice(idx, 1)
    } else {
        expandedGroups.value.push(label)
    }
}

function isGroupExpanded(label: string): boolean {
    return expandedGroups.value.includes(label)
}

// ── Flyout panel (collapsed sidebar hover) ─────────────────────────────────────
const hoveredGroup = ref<any>(null)
const flyoutTop = ref(0)
let flyoutHideTimer: ReturnType<typeof setTimeout> | null = null

function showFlyout(group: any, evt: MouseEvent) {
    if (!collapsed.value) return
    if (flyoutHideTimer) { clearTimeout(flyoutHideTimer); flyoutHideTimer = null }
    hoveredGroup.value = group
    flyoutTop.value = (evt.currentTarget as HTMLElement).getBoundingClientRect().top
}

function scheduleFlyoutHide() {
    flyoutHideTimer = setTimeout(() => {
        hoveredGroup.value = null
        flyoutHideTimer = null
    }, 80)
}

function keepFlyoutOpen() {
    if (flyoutHideTimer) { clearTimeout(flyoutHideTimer); flyoutHideTimer = null }
}

// ── Alerts ────────────────────────────────────────────────────────────────────
const alerts = ref<Array<{ id: number; title: string; severity: string; source_module: string }>>([])
const alertCount = ref(0)
const showAlerts = ref(false)

async function loadAlerts() {
    try {
        const res = await axios.get('/alerts?unread=1&limit=5')
        alerts.value = res.data.alerts ?? []
        alertCount.value = res.data.total_unread ?? 0
    } catch { /* non-blocking */ }
}

// ── Chat unread badge ──────────────────────────────────────────────────────────
const chatUnread = ref(0)

async function loadChatUnread() {
    try {
        const res = await axios.get('/chat/channels')
        const channels = res.data?.channels ?? []
        chatUnread.value = channels.reduce(
            (sum: number, c: { unread_count?: number }) => sum + (c.unread_count ?? 0),
            0,
        )
    } catch { /* non-blocking */ }
}

// ── HIPAA idle timeout ─────────────────────────────────────────────────────────
const showIdleWarning = ref(false)
const idleCountdown = ref(60)

const autoLogoutMinutes = computed(
    () => (user.value as { tenant?: { auto_logout_minutes?: number } } | null)?.tenant?.auto_logout_minutes ?? 15,
)

let warningTimer: ReturnType<typeof setTimeout> | null = null
let countdownInterval: ReturnType<typeof setInterval> | null = null
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'] as const

function clearIdleTimers() {
    if (warningTimer) clearTimeout(warningTimer)
    if (countdownInterval) clearInterval(countdownInterval)
}

function startIdleTimers() {
    clearIdleTimers()
    const warnAfterMs = (autoLogoutMinutes.value - 1) * 60 * 1000
    warningTimer = setTimeout(() => {
        showIdleWarning.value = true
        idleCountdown.value = 60
        countdownInterval = setInterval(() => {
            idleCountdown.value--
            if (idleCountdown.value <= 0) {
                clearInterval(countdownInterval!)
                router.post('/auth/logout', { timeout: true } as any)
            }
        }, 1000)
    }, warnAfterMs)
}

function handleActivity() {
    if (!showIdleWarning.value) startIdleTimers()
}

function stayLoggedIn() {
    showIdleWarning.value = false
    idleCountdown.value = 60
    startIdleTimers()
}

// ── Impersonation ──────────────────────────────────────────────────────────────
async function stopImpersonation() {
    await axios.delete('/super-admin/impersonate')
    router.reload()
}

// ── Logout ─────────────────────────────────────────────────────────────────────
function logout() {
    router.post('/auth/logout')
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────
onMounted(() => {
    applyTheme(theme.value)

    // Restore per-user sidebar preference (default: expanded)
    const saved = localStorage.getItem(sidebarKey())
    collapsed.value = saved === 'collapsed'

    // Auto-expand groups containing the active route
    navGroups.value.forEach((group: any) => {
        if (isGroupActive(group)) expandedGroups.value.add(group.label)
    })

    loadAlerts()
    loadChatUnread()

    if (window.Echo && user.value) {
        window.Echo.private(`tenant.${user.value.tenant_id}`).listen('AlertCreated', () => loadAlerts())
        window.Echo.private(`user.${user.value.id}`).listen('ChatActivity', () => loadChatUnread())
    }

    startIdleTimers()
    ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, handleActivity, { passive: true }))
})

onUnmounted(() => {
    clearIdleTimers()
    if (flyoutHideTimer) clearTimeout(flyoutHideTimer)
    ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, handleActivity))
})
</script>

<template>
    <!-- HIPAA idle timeout warning -->
    <IdleWarningModal
        v-if="showIdleWarning"
        :countdown="idleCountdown"
        @stay-logged-in="stayLoggedIn"
    />

    <!-- Impersonation banner -->
    <div
        v-if="impersonation.active && impersonation.user"
        class="bg-amber-400 text-amber-900 px-4 py-2 flex items-center gap-2 text-sm font-semibold z-50"
    >
        <EyeIcon class="w-4 h-4" aria-hidden="true" />
        Viewing as {{ impersonation.user.first_name }} {{ impersonation.user.last_name }}
        &middot; {{ impersonation.user.department_label }}
        <button
            class="ml-auto px-3 py-0.5 bg-amber-700 text-white rounded text-xs hover:bg-amber-800 transition"
            aria-label="Exit impersonation"
            @click="stopImpersonation"
        >
            Exit Impersonation
        </button>
    </div>

    <div class="flex h-screen bg-gray-50 dark:bg-slate-900 overflow-hidden">
        <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
        <aside
            :class="[
                'flex flex-col bg-slate-900 dark:bg-slate-950 transition-all duration-200 shrink-0 z-40',
                collapsed ? 'w-16' : 'w-64',
            ]"
        >
            <!-- Logo -->
            <div
                :class="[
                    'h-14 flex items-center border-b border-slate-700/50 shrink-0',
                    collapsed ? 'justify-center' : 'px-4',
                ]"
            >
                <span v-if="!collapsed" class="text-white font-bold text-lg tracking-tight select-none">
                    Nostos<span class="text-indigo-400">EMR</span>
                </span>
                <span v-else class="text-indigo-400 font-bold text-xl select-none">N</span>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-0.5" aria-label="Main navigation">
                <!-- Dashboard — top-level fixed item -->
                <button
                    :class="[
                        'w-full flex items-center gap-3 px-2 py-2 rounded-md text-sm font-medium transition-colors',
                        currentPath.startsWith('/dashboard')
                            ? 'bg-indigo-600 text-white'
                            : 'text-slate-300 hover:bg-slate-700/60 hover:text-white',
                        collapsed ? 'justify-center' : '',
                    ]"
                    aria-label="Dashboard"
                    :aria-current="currentPath.startsWith('/dashboard') ? 'page' : undefined"
                    @click="navigate(dashboardHref)"
                >
                    <HomeIcon class="shrink-0 w-5 h-5" aria-hidden="true" />
                    <span v-if="!collapsed" class="truncate">Dashboard</span>
                </button>

                <!-- Nav groups -->
                <template v-for="group in navGroups" :key="group.label">
                    <!-- Group header -->
                    <button
                        :class="[
                            'w-full flex items-center gap-3 px-2 py-2 rounded-md text-sm font-medium transition-colors',
                            isGroupActive(group)
                                ? collapsed
                                    ? 'text-indigo-300 bg-indigo-600/10'
                                    : 'text-slate-200'
                                : 'text-slate-400 hover:text-white hover:bg-slate-700/60',
                            collapsed ? 'justify-center' : '',
                        ]"
                        :aria-label="group.label"
                        :aria-expanded="!collapsed ? isGroupExpanded(group.label) : undefined"
                        @click="collapsed ? undefined : toggleGroup(group.label)"
                        @mouseenter="showFlyout(group, $event)"
                        @mouseleave="scheduleFlyoutHide"
                    >
                        <component
                            :is="groupIcon(group.icon)"
                            class="shrink-0 w-5 h-5"
                            aria-hidden="true"
                        />
                        <template v-if="!collapsed">
                            <span class="truncate flex-1 text-left">{{ group.label }}</span>
                            <ChevronRightIcon
                                :class="[
                                    'w-4 h-4 shrink-0 transition-transform duration-150',
                                    isGroupExpanded(group.label) ? 'rotate-90' : '',
                                ]"
                                aria-hidden="true"
                            />
                        </template>
                    </button>

                    <!-- Group items (expanded sidebar accordion) -->
                    <div
                        v-if="!collapsed && isGroupExpanded(group.label)"
                        class="ml-2 pl-3 border-l border-slate-700/40 space-y-0.5 py-0.5"
                    >
                        <button
                            v-for="item in group.items"
                            :key="item.module"
                            :class="[
                                'w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-sm transition-colors',
                                isActive(item.href)
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-slate-400 hover:bg-slate-700/50 hover:text-white',
                            ]"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @click="navigate(item.href)"
                        >
                            <component
                                :is="navIcon(item.module)"
                                class="shrink-0 w-4 h-4"
                                aria-hidden="true"
                            />
                            <span class="truncate flex-1 text-left">{{ item.label }}</span>
                            <span
                                v-if="item.badge && item.badge > 0"
                                class="ml-auto bg-red-500 text-white text-xs font-bold rounded-full px-1.5 py-0.5 min-w-[1.25rem] text-center"
                                :aria-label="`${item.badge} unread`"
                            >
                                {{ item.badge }}
                            </span>
                        </button>
                    </div>
                </template>
            </nav>

            <!-- Sidebar footer -->
            <div class="border-t border-slate-700/50 p-3 shrink-0 space-y-2">
                <!-- Dashboard View selector (super admin only, not when impersonating) -->
                <div
                    v-if="user?.is_super_admin && !impersonation.active && !collapsed"
                    class="rounded-lg bg-slate-800 border border-slate-700 p-2.5 space-y-1.5"
                >
                    <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Dashboard View</p>
                    <select
                        class="w-full bg-slate-700 text-slate-200 text-xs rounded px-2 py-1.5 border border-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                        :value="page.props.impersonation?.viewing_as_dept ?? 'it_admin'"
                        @change="(e) => axios.post('/super-admin/view-as', { department: (e.target as HTMLSelectElement).value }).then(() => router.reload())"
                    >
                        <option value="primary_care">Primary Care</option>
                        <option value="home_care">Home Care</option>
                        <option value="therapies">Therapies</option>
                        <option value="social_work">Social Work</option>
                        <option value="nutrition">Nutrition</option>
                        <option value="behavioral_health">Behavioral Health</option>
                        <option value="idt">IDT</option>
                        <option value="transportation">Transportation</option>
                        <option value="pharmacy">Pharmacy</option>
                        <option value="enrollment">Enrollment</option>
                        <option value="finance">Finance</option>
                        <option value="qa_compliance">QA / Compliance</option>
                        <option value="it_admin">IT Admin</option>
                        <option value="executive">Executive</option>
                    </select>
                    <p class="text-[10px] text-slate-500 leading-tight">
                        Controls dashboard module cards only. All pages remain accessible.
                    </p>
                </div>

                <!-- User identity -->
                <div v-if="!collapsed && user" class="px-1 space-y-1">
                    <p class="text-xs font-medium text-slate-200 truncate">
                        {{ user.first_name }} {{ user.last_name }}
                    </p>
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-[10px] bg-indigo-700/60 text-indigo-300 px-1.5 py-0.5 rounded">
                            {{ user.department_label }}
                        </span>
                        <span
                            v-if="user.is_super_admin"
                            class="text-[10px] bg-amber-700/60 text-amber-300 px-1.5 py-0.5 rounded"
                        >
                            super_admin
                        </span>
                    </div>
                </div>

                <!-- Logout -->
                <button
                    :class="[
                        'w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-sm text-slate-400 hover:text-red-400 hover:bg-slate-700/50 transition-colors',
                        collapsed ? 'justify-center' : '',
                    ]"
                    aria-label="Sign out"
                    @click="logout"
                >
                    <ArrowRightOnRectangleIcon class="w-4 h-4 shrink-0" aria-hidden="true" />
                    <span v-if="!collapsed">Log out</span>
                </button>
            </div>
        </aside>

        <!-- ── Flyout panel (rendered in body to avoid sidebar z-index clipping) ── -->
        <Teleport to="body">
            <div
                v-if="collapsed && hoveredGroup"
                class="fixed z-[60] bg-slate-800 border border-slate-700 rounded-md shadow-2xl py-1.5 min-w-[200px]"
                :style="{ top: `${flyoutTop}px`, left: '64px' }"
                @mouseenter="keepFlyoutOpen"
                @mouseleave="scheduleFlyoutHide"
            >
                <p class="px-3 pt-1 pb-1.5 text-[10px] font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-700">
                    {{ hoveredGroup.label }}
                </p>
                <div class="py-1">
                    <button
                        v-for="item in hoveredGroup.items"
                        :key="item.module"
                        :class="[
                            'w-full flex items-center gap-2.5 px-3 py-1.5 text-sm transition-colors text-left',
                            isActive(item.href)
                                ? 'bg-indigo-600 text-white'
                                : 'text-slate-300 hover:bg-slate-700 hover:text-white',
                        ]"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        @click="navigate(item.href)"
                    >
                        <component :is="navIcon(item.module)" class="w-4 h-4 shrink-0" aria-hidden="true" />
                        {{ item.label }}
                    </button>
                </div>
            </div>
        </Teleport>

        <!-- ── Main area ──────────────────────────────────────────────────────── -->
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
            <!-- Top bar -->
            <header
                class="h-14 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 flex items-center px-3 gap-2 shrink-0 z-30"
            >
                <!-- Hamburger: toggles sidebar collapse -->
                <button
                    class="p-2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition shrink-0"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="toggleSidebar"
                >
                    <Bars3Icon class="w-5 h-5" aria-hidden="true" />
                </button>

                <div class="flex-1 min-w-0">
                    <slot name="header" />
                </div>

                <div class="flex items-center gap-1 shrink-0">
                    <!-- Chat -->
                    <button
                        class="relative p-2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition"
                        aria-label="Open chat"
                        @click="navigate('/chat')"
                    >
                        <ChatBubbleLeftRightIcon class="w-5 h-5" aria-hidden="true" />
                        <span
                            v-if="chatUnread > 0"
                            class="absolute top-1 right-1 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center"
                            :aria-label="`${chatUnread} unread messages`"
                        >
                            {{ chatUnread > 9 ? '9+' : chatUnread }}
                        </span>
                    </button>

                    <!-- Alert bell -->
                    <div class="relative">
                        <button
                            class="relative p-2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition"
                            :aria-label="`Alerts${alertCount > 0 ? ` - ${alertCount} active` : ''}`"
                            :aria-expanded="showAlerts"
                            aria-haspopup="true"
                            @click="showAlerts = !showAlerts"
                        >
                            <BellIcon class="w-5 h-5" aria-hidden="true" />
                            <span
                                v-if="alertCount > 0"
                                class="absolute top-1 right-1 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center"
                                aria-hidden="true"
                            >
                                {{ alertCount > 9 ? '9+' : alertCount }}
                            </span>
                        </button>

                        <!-- Alert dropdown -->
                        <div
                            v-if="showAlerts"
                            class="absolute right-0 top-full mt-1 w-80 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg z-50"
                            role="menu"
                        >
                            <div class="p-3 border-b border-gray-100 dark:border-slate-700 text-sm font-semibold text-gray-700 dark:text-slate-200">
                                Active Alerts
                            </div>
                            <div
                                v-if="alerts.length === 0"
                                class="p-4 text-sm text-gray-500 dark:text-slate-400 text-center"
                            >
                                No active alerts
                            </div>
                            <div
                                v-for="alert in alerts"
                                :key="alert.id"
                                class="p-3 border-b border-gray-50 dark:border-slate-700 last:border-0"
                                role="menuitem"
                            >
                                <div class="text-sm font-medium text-gray-800 dark:text-slate-200">{{ alert.title }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 capitalize">
                                    {{ alert.severity }} - {{ alert.source_module }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Theme toggle -->
                    <button
                        class="p-2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition"
                        :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                        @click="toggleTheme"
                    >
                        <SunIcon v-if="theme === 'dark'" class="w-5 h-5" aria-hidden="true" />
                        <MoonIcon v-else class="w-5 h-5" aria-hidden="true" />
                    </button>

                    <!-- Profile -->
                    <button
                        class="p-2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition"
                        aria-label="Go to your profile"
                        @click="navigate('/profile')"
                    >
                        <UserIcon class="w-5 h-5" aria-hidden="true" />
                    </button>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 overflow-y-auto">
                <slot />
            </main>
        </div>
    </div>
</template>
