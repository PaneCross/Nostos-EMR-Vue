<script setup lang="ts">
// ─── AppShell.vue ─────────────────────────────────────────────────────────────
// Root layout for all authenticated NostosEMR pages.
// Provides: collapsed sidebar nav, dark-mode toggle, alert bell, chat badge,
//           impersonation banner, HIPAA idle-timeout modal, and page content slot.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import {
    BellIcon,
    MoonIcon,
    SunIcon,
    UserIcon,
    ArrowRightOnRectangleIcon,
    EyeIcon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ChatBubbleLeftRightIcon,
} from '@heroicons/vue/24/outline'
import type { PageProps } from '@/types'
import IdleWarningModal from '@/Components/IdleWarningModal.vue'

// ── Shared props ───────────────────────────────────────────────────────────────
const page = usePage<PageProps>()
const auth = computed(() => page.props.auth)
const user = computed(() => auth.value?.user ?? null)
const impersonation = computed(() => page.props.impersonation)

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

// ── Nav ────────────────────────────────────────────────────────────────────────
const navGroups = computed(() => user.value.nav_groups ?? [])
const currentPath = computed(() => window.location.pathname)

function isActive(href: string): boolean {
    const all = navGroups.value.flatMap((g) => g.items.map((i) => i.href))
    const longer = all.filter((h) => h !== href && h.startsWith(href) && h.length > href.length)
    if (longer.some((h) => currentPath.value.startsWith(h))) return false
    return currentPath.value === href || currentPath.value.startsWith(href + '/')
}

function navigate(href: string) {
    router.visit(href)
}

// ── Sidebar ────────────────────────────────────────────────────────────────────
const collapsed = ref(true)

// ── Alerts ────────────────────────────────────────────────────────────────────
const alerts = ref<Array<{ id: number; title: string; severity: string; source_module: string }>>([])
const alertCount = ref(0)
const showAlerts = ref(false)

async function loadAlerts() {
    try {
        const res = await axios.get('/alerts?unread=1&limit=5')
        alerts.value = res.data.alerts ?? []
        alertCount.value = res.data.total_unread ?? 0
    } catch {
        // Non-blocking — alert badge just won't update
    }
}

// ── Chat unread badge ──────────────────────────────────────────────────────────
const chatUnread = ref(0)

async function loadChatUnread() {
    try {
        const res = await axios.get('/chat/unread')
        chatUnread.value = res.data.count ?? 0
    } catch {
        // Non-blocking
    }
}

// ── HIPAA idle timeout ─────────────────────────────────────────────────────────
// Shows a warning modal 60s before auto-logout. Resets on any user activity.
// autoLogoutMinutes comes from the tenant config (default 15 min).
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

    // Fire warning 1 minute before the configured auto-logout time
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
    // Only reset the timer when the warning modal is NOT showing;
    // once the modal is up the user must click "Stay Logged In" explicitly.
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

    loadAlerts()
    loadChatUnread()

    // Real-time subscriptions via Reverb
    if (window.Echo && user.value) {
        window.Echo.private(`tenant.${user.value.tenant_id}`).listen('AlertCreated', () => {
            loadAlerts()
        })
        window.Echo.private(`user.${user.value.id}`).listen('ChatActivity', () => {
            loadChatUnread()
        })
    }

    // Start idle timer and attach activity listeners
    startIdleTimers()
    ACTIVITY_EVENTS.forEach((evt) =>
        window.addEventListener(evt, handleActivity, { passive: true }),
    )
})

onUnmounted(() => {
    clearIdleTimers()
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
                collapsed ? 'w-16' : 'w-56',
            ]"
        >
            <!-- Logo -->
            <div class="h-14 flex items-center justify-center border-b border-slate-700/50 shrink-0">
                <span class="text-white font-bold text-lg tracking-tight">
                    {{ collapsed ? 'N' : 'NostosEMR' }}
                </span>
            </div>

            <!-- Nav items -->
            <nav class="flex-1 overflow-y-auto py-2 space-y-1 px-1" aria-label="Main navigation">
                <template v-for="group in navGroups" :key="group.label">
                    <div
                        v-if="!collapsed"
                        class="px-2 pt-3 pb-1 text-xs font-semibold text-slate-400 uppercase tracking-wider"
                    >
                        {{ group.label }}
                    </div>
                    <button
                        v-for="item in group.items"
                        :key="item.module"
                        :class="[
                            'w-full flex items-center gap-3 px-2 py-2 rounded text-sm transition',
                            isActive(item.href)
                                ? 'bg-indigo-600 text-white'
                                : 'text-slate-300 hover:bg-slate-700/50 hover:text-white',
                            collapsed ? 'justify-center' : '',
                        ]"
                        :aria-label="collapsed ? item.label : undefined"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        @click="navigate(item.href)"
                    >
                        <span class="shrink-0 w-5 h-5 flex items-center justify-center text-xs font-bold">
                            {{ item.label.charAt(0) }}
                        </span>
                        <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                        <span
                            v-if="item.badge && item.badge > 0 && !collapsed"
                            class="ml-auto bg-red-500 text-white text-xs font-bold rounded-full px-1.5 py-0.5 min-w-[1.25rem] text-center"
                            :aria-label="`${item.badge} unread`"
                        >
                            {{ item.badge }}
                        </span>
                    </button>
                </template>
            </nav>

            <!-- Sidebar footer -->
            <div class="border-t border-slate-700/50 p-2 shrink-0">
                <div v-if="!collapsed && user" class="text-xs text-slate-400 px-2 pb-2 truncate">
                    {{ user.first_name }} {{ user.last_name }}
                    <span class="block text-slate-500">{{ user.department_label }}</span>
                </div>
                <button
                    class="w-full flex items-center justify-center py-1.5 text-slate-400 hover:text-white hover:bg-slate-700/50 rounded transition"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="collapsed = !collapsed"
                >
                    <ChevronDoubleRightIcon v-if="collapsed" class="w-4 h-4" aria-hidden="true" />
                    <ChevronDoubleLeftIcon v-else class="w-4 h-4" aria-hidden="true" />
                </button>
            </div>
        </aside>

        <!-- ── Main area ──────────────────────────────────────────────────────── -->
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
            <!-- Top bar -->
            <header
                class="h-14 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 flex items-center px-4 gap-3 shrink-0 z-30"
            >
                <div class="flex-1 min-w-0">
                    <slot name="header" />
                </div>

                <div class="flex items-center gap-2 shrink-0">
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
                            :aria-label="`Alerts${alertCount > 0 ? ` — ${alertCount} active` : ''}`"
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
                                <div class="text-sm font-medium text-gray-800 dark:text-slate-200">
                                    {{ alert.title }}
                                </div>
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

                    <!-- Logout -->
                    <button
                        class="p-2 text-gray-500 dark:text-slate-400 hover:text-red-600 dark:hover:text-red-400 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition"
                        aria-label="Sign out"
                        @click="logout"
                    >
                        <ArrowRightOnRectangleIcon class="w-5 h-5" aria-hidden="true" />
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
