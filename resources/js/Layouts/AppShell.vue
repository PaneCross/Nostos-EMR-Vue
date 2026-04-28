<script setup lang="ts">
// ─── AppShell.vue ─────────────────────────────────────────────────────────────
// Root layout for all authenticated NostosEMR pages.
//
// What lives here:
//   - The left sidebar (collapsible via hamburger, default expanded;
//     per-user preference saved in localStorage).
//   - The top app bar (tenant + site context, user menu, dark-mode toggle,
//     notifications bell, Cmd+K global search).
//   - The Toaster component (V5/W2: surfaces axios errors as toasts).
//   - The IdleWarningModal (HIPAA-required inactivity warning + auto-logout).
//
// Nav style: when expanded, the sidebar shows a group-accordion. When
// collapsed, hovering an icon shows a flyout panel. Department-specific
// nav items are filtered server-side (HandleInertiaRequests middleware
// passes the current user's permissions) so this file just renders what's
// allowed.
//
// Stack note: this is THE only layout. Every Inertia page renders inside it
// (set per-page via `defineOptions({ layout: AppShell })`). New pages only
// need a layout opt-in if they're full-bleed (login, OAuth, error pages).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted, type Component } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import GlobalSearch from '@/Components/GlobalSearch.vue'
import {
    BellIcon,
    MoonIcon,
    SunIcon,
    ArrowRightOnRectangleIcon,
    EyeIcon,
    ChatBubbleLeftRightIcon,
    Bars3Icon,
    HomeIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    MagnifyingGlassIcon as SearchIcon,
    QuestionMarkCircleIcon as HelpIcon,
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
    ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/24/outline'
import type { PageProps, SiteContext } from '@/types'
import IdleWarningModal from '@/Components/IdleWarningModal.vue'
import Toaster from '@/Components/Toaster.vue'

// ── Nav types (mirrors PermissionService output) ───────────────────────────────
interface NavItem {
    label: string
    href: string
    module: string
}
interface NavGroup {
    label: string
    icon: string
    items: NavItem[]
}

// ── Shared props ───────────────────────────────────────────────────────────────
const page = usePage<PageProps>()
const auth = computed(() => page.props.auth)
const user = computed(() => auth.value?.user ?? null)
const impersonation = computed(() => page.props.impersonation ?? { active: false, user: null, viewing_as_dept: null })
const siteContext = computed(() => page.props.site_context as SiteContext | null)
const availableSites = computed(() => (page.props.available_sites as SiteContext[]) ?? [])
const canSwitchSite = computed(() =>
    (user.value?.is_super_admin || user.value?.department === 'executive') && availableSites.value.length > 1
)

// ── Global search ──────────────────────────────────────────────────────────────
const showSearch = ref(false)

// ── Help popover ───────────────────────────────────────────────────────────────
const showHelp = ref(false)

// ── User chip dropdown ─────────────────────────────────────────────────────────
const showUserMenu = ref(false)

// ── Site switcher dropdown ─────────────────────────────────────────────────────
const showSiteSwitcher = ref(false)
const switchingSite = ref<number | null>(null)

function switchSite(siteId: number) {
    switchingSite.value = siteId
    axios.post('/site-context/switch', { site_id: siteId })
        .then(() => { showSiteSwitcher.value = false; router.reload() })
        .catch(() => { switchingSite.value = null })
}

// ── Imitate User dropdown ──────────────────────────────────────────────────────
const showImitate = ref(false)
const imitateUsers = ref<Array<{ id: number; first_name: string; last_name: string; department_label: string; role: string }>>([])
const imitateQuery = ref('')
const imitateLoading = ref(false)
const imitateStarting = ref<number | null>(null)

const imitateFiltered = computed(() => {
    const q = imitateQuery.value.toLowerCase()
    if (!q) return imitateUsers.value
    return imitateUsers.value.filter(u =>
        `${u.first_name} ${u.last_name}`.toLowerCase().includes(q) ||
        u.department_label.toLowerCase().includes(q) ||
        u.role.toLowerCase().includes(q)
    )
})

async function openImitate() {
    showImitate.value = !showImitate.value
    if (showImitate.value && imitateUsers.value.length === 0) {
        imitateLoading.value = true
        try {
            const res = await axios.get('/super-admin/users')
            imitateUsers.value = res.data.users ?? []
        } finally {
            imitateLoading.value = false
        }
    }
}

async function startImpersonation(userId: number) {
    imitateStarting.value = userId
    try {
        await axios.post(`/super-admin/impersonate/${userId}`)
        showImitate.value = false
        router.reload()
    } catch {
        imitateStarting.value = null
    }
}

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
const navGroups = computed(() => (page.props.nav_groups as NavGroup[]) ?? [])
const currentPath = computed(() => window.location.pathname)

function isActive(href: string): boolean {
    const all: string[] = navGroups.value.flatMap((g: NavGroup) => g.items.map((i: NavItem) => i.href))
    const longer = all.filter((h) => h !== href && h.startsWith(href) && h.length > href.length)
    if (longer.some((h) => currentPath.value.startsWith(h))) return false
    return currentPath.value === href || currentPath.value.startsWith(href + '/')
}

function isGroupActive(group: NavGroup): boolean {
    return group.items.some((item: NavItem) => isActive(item.href))
}

function navigate(href: string) {
    router.visit(href)
    hoveredGroup.value = null
}

function switchDashboardView(e: Event) {
    const dept = (e.target as HTMLSelectElement).value
    if (!dept) return
    axios.post('/super-admin/view-as', { department: dept }).then(() => {
        location.href = '/'
    })
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
// NOTE: Vue 3 ref<Set> does NOT trigger reactivity on .add()/.delete():
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
const hoveredGroup = ref<NavGroup | null>(null)
const flyoutTop = ref(0)
let flyoutHideTimer: ReturnType<typeof setTimeout> | null = null

function showFlyout(group: NavGroup, evt: MouseEvent) {
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
        // Two concurrent calls:
        //  - /alerts/unread-count: authoritative badge number (matches the "View All" page's unread filter)
        //  - /alerts?unread_only=1&per_page=5: first 5 unread alerts for the dropdown preview
        // Both match AlertController::index() / unreadCount() server-side param names.
        const [countRes, listRes] = await Promise.all([
            axios.get('/alerts/unread-count'),
            axios.get('/alerts', {
                headers: { Accept: 'application/json' },
                params: { unread_only: '1', per_page: 5 },
            }),
        ])
        alertCount.value = countRes.data?.count ?? 0
        // AlertController returns a Laravel paginator envelope: { data: [...], total, ... }.
        alerts.value = (listRes.data?.data ?? []).slice(0, 5)
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
                router.post('/auth/logout', { timeout: true })
            }
        }, 1000)
    }, warnAfterMs)
}

function handleActivity() {
    if (!showIdleWarning.value) startIdleTimers()
}

async function stayLoggedIn() {
    // Phase P1: hit /auth/heartbeat so the BACKEND session is extended,
    // not just the JS timer. Without this, the modal resets but the server
    // session can still expire silently mid-session.
    try {
        await axios.post('/auth/heartbeat')
    } catch { /* non-blocking; if heartbeat fails the session was already gone */ }
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
    navGroups.value.forEach((group: NavGroup) => {
        if (isGroupActive(group)) expandedGroups.value.push(group.label)
    })

    loadAlerts()
    loadChatUnread()

    if (window.Echo && user.value) {
        window.Echo.private(`tenant.${user.value.tenant?.id}`).listen('AlertCreated', () => loadAlerts())
        window.Echo.private(`user.${user.value.id}`).listen('ChatActivity', () => loadChatUnread())
    }

    // Cmd+K / Ctrl+K → open global search
    window.addEventListener('keydown', handleGlobalKey)

    startIdleTimers()
    ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, handleActivity, { passive: true }))
})

onUnmounted(() => {
    clearIdleTimers()
    if (flyoutHideTimer) clearTimeout(flyoutHideTimer)
    ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, handleActivity))
    window.removeEventListener('keydown', handleGlobalKey)
})

function handleGlobalKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        showSearch.value = true
    }
    // Close dropdowns on Escape
    if (e.key === 'Escape') {
        showHelp.value = false
        showUserMenu.value = false
        showSiteSwitcher.value = false
        showImitate.value = false
    }
}
</script>

<template>
    <!-- HIPAA idle timeout warning -->
    <IdleWarningModal
        v-if="showIdleWarning"
        :countdown="idleCountdown"
        @stay-logged-in="stayLoggedIn"
    />

    <!-- Phase V5: global toast surface for axios errors + ad-hoc emits -->
    <Toaster />

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
                <!-- Dashboard: top-level fixed item -->
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
                    @click="navigate('/')"
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
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Dashboard View</p>
                    <select name="dashboard-view"
                        class="w-full bg-slate-700 text-slate-200 text-xs rounded px-2 py-1.5 border border-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                        :value="page.props.impersonation?.viewing_as_dept ?? 'it_admin'"
                        @change="switchDashboardView"
                    >
                        <option value="primary_care">Primary Care</option>
                        <option value="home_care">Home Care</option>
                        <option value="therapies">Therapies</option>
                        <option value="social_work">Social Work</option>
                        <option value="dietary">Dietary / Nutrition</option>
                        <option value="activities">Activities</option>
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
                    <p class="text-xs text-slate-500 leading-tight">
                        Controls dashboard module cards only. All pages remain accessible.
                    </p>
                </div>

                <!-- User identity -->
                <div v-if="!collapsed && user" class="px-1 space-y-1">
                    <p class="text-xs font-medium text-slate-200 truncate">
                        {{ user.first_name }} {{ user.last_name }}
                    </p>
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-xs bg-indigo-700/60 text-indigo-300 px-1.5 py-0.5 rounded">
                            {{ user.department_label }}
                        </span>
                        <span
                            v-if="user.is_super_admin"
                            class="text-xs bg-amber-700/60 text-amber-300 px-1.5 py-0.5 rounded"
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
                <p class="px-3 pt-1 pb-1.5 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-700">
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
            <!-- ── Top bar ──────────────────────────────────────────────────── -->
            <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center px-4 gap-3 shrink-0 z-30">

                <!-- Hamburger -->
                <button
                    class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors shrink-0"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="toggleSidebar"
                >
                    <Bars3Icon class="w-5 h-5" aria-hidden="true" />
                </button>

                <!-- Tenant + site display -->
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="font-semibold text-slate-800 dark:text-slate-100 truncate">
                        {{ user?.tenant?.name }}
                    </span>
                    <template v-if="siteContext || user?.site">
                        <span class="text-slate-300 dark:text-slate-600">·</span>
                        <span class="text-slate-500 dark:text-slate-400 truncate">
                            {{ (siteContext ?? user?.site)?.name }}
                        </span>
                    </template>
                </div>

                <!-- Site switcher dropdown -->
                <div v-if="canSwitchSite" class="relative shrink-0">
                    <button
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-lg transition-colors"
                        @click="showSiteSwitcher = !showSiteSwitcher"
                    >
                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        {{ (siteContext ?? user?.site)?.name ?? 'Select Site' }}
                        <ChevronDownIcon class="w-3.5 h-3.5 text-slate-400" aria-hidden="true" />
                    </button>
                    <div
                        v-if="showSiteSwitcher"
                        class="absolute left-0 top-full mt-1 w-52 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg z-50 py-1 overflow-hidden"
                    >
                        <button
                            v-for="site in availableSites"
                            :key="site.id"
                            :class="[
                                'w-full flex items-center gap-2 px-3 py-2 text-sm text-left transition-colors',
                                site.id === (siteContext ?? user?.site)?.id
                                    ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 font-medium'
                                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700',
                            ]"
                            :disabled="switchingSite === site.id"
                            @click="switchSite(site.id)"
                        >
                            {{ site.name }}
                            <span v-if="switchingSite === site.id" class="ml-auto text-xs text-slate-400">Switching...</span>
                        </button>
                    </div>
                </div>

                <!-- Global search -->
                <button
                    class="hidden sm:flex items-center gap-2 px-3 py-1.5 text-sm text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-lg transition-colors shrink-0"
                    aria-label="Open global search"
                    @click="showSearch = true"
                >
                    <SearchIcon class="w-4 h-4" aria-hidden="true" />
                    <span>Search participants...</span>
                    <kbd class="ml-1 px-1.5 py-0.5 text-xs font-medium bg-white dark:bg-slate-600 border border-slate-200 dark:border-slate-500 rounded">
                        ⌘K
                    </kbd>
                </button>

                <!-- Right-side controls -->
                <div class="flex items-center gap-1 shrink-0">

                    <!-- Theme toggle -->
                    <button
                        class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors"
                        :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                        @click="toggleTheme"
                    >
                        <SunIcon v-if="theme === 'dark'" class="w-5 h-5" aria-hidden="true" />
                        <MoonIcon v-else class="w-5 h-5" aria-hidden="true" />
                    </button>

                    <!-- Imitate User (super_admin only) -->
                    <div v-if="user?.is_super_admin" class="relative">
                        <button
                            :class="[
                                'p-1.5 rounded-lg transition-colors',
                                impersonation.active
                                    ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 ring-2 ring-amber-400'
                                    : 'hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400',
                            ]"
                            :aria-label="impersonation.active ? 'Currently impersonating a user' : 'Imitate user'"
                            @click="openImitate"
                        >
                            <!-- User-switch icon -->
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 12l2.25 2.25-2.25 2.25M21 14.25h-6" />
                            </svg>
                        </button>
                        <!-- Imitate dropdown -->
                        <div
                            v-if="showImitate"
                            class="absolute right-0 top-10 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden"
                        >
                            <div class="px-3 py-2.5 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80">
                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 mb-2">Imitate User</p>
                                <input
                                    v-model="imitateQuery"
                                    type="text"
                                    placeholder="Search by name or department..."
                                    autofocus
                                    class="w-full text-xs px-2.5 py-1.5 border border-slate-200 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                                />
                            </div>
                            <div class="max-h-64 overflow-y-auto">
                                <p v-if="imitateLoading" class="text-xs text-slate-500 dark:text-slate-400 px-4 py-3 text-center">Loading users...</p>
                                <p v-else-if="imitateFiltered.length === 0" class="text-xs text-slate-500 dark:text-slate-400 px-4 py-3 text-center">No users found</p>
                                <button
                                    v-for="u in imitateFiltered"
                                    :key="u.id"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-blue-50 dark:hover:bg-slate-700 text-left transition-colors disabled:opacity-50"
                                    :disabled="imitateStarting === u.id"
                                    @click="startImpersonation(u.id)"
                                >
                                    <div class="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300 flex items-center justify-center text-xs font-semibold shrink-0">
                                        {{ u.first_name[0] }}{{ u.last_name[0] }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-medium text-slate-800 dark:text-slate-100 truncate">{{ u.first_name }} {{ u.last_name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ u.department_label }} · {{ u.role }}</p>
                                    </div>
                                    <span v-if="imitateStarting === u.id" class="text-xs text-blue-500">Starting...</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Notification bell -->
                    <div class="relative">
                        <button
                            class="relative p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors"
                            :aria-label="`Alerts${alertCount > 0 ? ` - ${alertCount} active` : ''}`"
                            :aria-expanded="showAlerts"
                            @click="showAlerts = !showAlerts"
                        >
                            <BellIcon class="w-5 h-5" aria-hidden="true" />
                            <span
                                v-if="alertCount > 0"
                                class="absolute top-0.5 right-0.5 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center"
                                aria-hidden="true"
                            >
                                {{ alertCount > 9 ? '9+' : alertCount }}
                            </span>
                        </button>
                        <div
                            v-if="showAlerts"
                            class="absolute right-0 top-full mt-1 w-80 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-lg z-50 overflow-hidden"
                        >
                            <div class="px-4 py-2.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Active Alerts</span>
                                <button
                                    class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                                    @click="showAlerts = false; navigate('/alerts')"
                                >
                                    View all
                                    <ArrowTopRightOnSquareIcon class="w-3 h-3" aria-hidden="true" />
                                </button>
                            </div>
                            <div v-if="alerts.length === 0" class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 text-center">
                                No active alerts
                            </div>
                            <div
                                v-for="alert in alerts"
                                :key="alert.id"
                                class="px-4 py-2.5 border-b border-slate-50 dark:border-slate-700/50 last:border-0"
                            >
                                <div class="text-sm font-medium text-slate-800 dark:text-slate-200 truncate">{{ alert.title }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 capitalize">
                                    {{ alert.severity }} · {{ alert.source_module }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Help -->
                    <div class="relative">
                        <button
                            class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors"
                            aria-label="Help"
                            @click="showHelp = !showHelp"
                        >
                            <HelpIcon class="w-5 h-5" aria-hidden="true" />
                        </button>
                        <div
                            v-if="showHelp"
                            class="absolute right-0 top-9 w-72 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 p-4 z-50"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Need Help?</h3>
                                <button class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 text-lg leading-none" @click="showHelp = false">&#x2715;</button>
                            </div>
                            <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">
                                If you are experiencing an issue with NostosEMR, please contact our support team:
                            </p>
                            <a href="mailto:support@nostos-emr.com" class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium block mb-3">
                                support@nostos-emr.com
                            </a>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                For urgent clinical system issues outside of business hours, contact your site IT Administrator.
                            </p>
                        </div>
                    </div>

                    <!-- User chip -->
                    <div class="relative flex items-center gap-2 pl-2 border-l border-slate-200 dark:border-slate-600">
                        <button
                            class="flex items-center gap-2 rounded-lg px-1 py-0.5 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                            aria-label="Account menu"
                            @click="showUserMenu = !showUserMenu"
                        >
                            <div
                                :class="[
                                    'w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0',
                                    impersonation.active ? 'bg-amber-500 ring-2 ring-amber-300' : 'bg-blue-600',
                                ]"
                            >
                                {{ user?.first_name?.[0] }}{{ user?.last_name?.[0] }}
                            </div>
                            <div class="hidden sm:block text-right">
                                <p class="text-xs font-medium text-slate-800 dark:text-slate-100 leading-none">{{ user?.first_name }} {{ user?.last_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ user?.department_label }}</p>
                            </div>
                            <ChevronDownIcon class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 hidden sm:block" :class="{ 'rotate-180': showUserMenu }" aria-hidden="true" />
                        </button>
                        <!-- User dropdown -->
                        <div
                            v-if="showUserMenu"
                            class="absolute right-0 top-full mt-2 w-52 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-lg z-50 overflow-hidden"
                        >
                            <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                                <p class="text-xs font-semibold text-slate-900 dark:text-slate-100 truncate">{{ user?.first_name }} {{ user?.last_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ user?.department_label }}</p>
                            </div>
                            <button
                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                @click="showUserMenu = false; navigate('/profile/notifications')"
                            >
                                <BellIcon class="w-4 h-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                                Notification Preferences
                            </button>
                            <button
                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                @click="showUserMenu = false; navigate('/my-credentials')"
                            >
                                <IdentificationIcon class="w-4 h-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                                My Credentials
                                <span v-if="(user as any)?.credentials_expiring_30d > 0"
                                    class="ml-auto px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                    {{ (user as any).credentials_expiring_30d }} expiring
                                </span>
                            </button>
                            <button
                                v-if="(user as any)?.has_direct_reports"
                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                @click="showUserMenu = false; navigate('/my-team')"
                            >
                                <IdentificationIcon class="w-4 h-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                                My Team
                            </button>
                            <div class="border-t border-slate-100 dark:border-slate-700">
                                <button
                                    class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors text-left"
                                    @click="showUserMenu = false; logout()"
                                >
                                    <ArrowRightOnRectangleIcon class="w-4 h-4 shrink-0" aria-hidden="true" />
                                    Sign Out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 flex flex-col overflow-y-auto">
                <slot />

                <!-- Phase 5 (MVP roadmap): policy surface links. Low-chrome footer. -->
                <footer class="mt-auto border-t border-slate-200 dark:border-slate-700/50 bg-slate-50 dark:bg-slate-900/40 px-6 py-3 text-xs text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <span>Confidential: 42 CFR §460.210</span>
                    <nav class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <a href="/policies/npp" class="hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">Notice of Privacy Practices</a>
                        <a href="/policies/info-blocking" class="hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">Information Blocking</a>
                        <a href="/policies/acceptable-use" class="hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">Acceptable Use</a>
                    </nav>
                </footer>
            </main>
        </div>
    </div>

    <!-- Global search modal (Cmd+K) -->
    <GlobalSearch :open="showSearch" @close="showSearch = false" />
</template>
