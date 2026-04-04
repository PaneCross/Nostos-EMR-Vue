<script setup lang="ts">
// Chat/Index.vue
// Two-column real-time chat interface.
// Left panel (w-72): Channel list grouped by type with unread badges.
//   "New Message" button opens a DM user-search input.
// Right panel: Message history (paginated), real-time updates via Laravel Echo.
//   Input bar with urgent toggle + Enter-to-send (Shift+Enter for newline).
// Real-time: subscribes to private-chat.{channelId} on channel select.
// Accessibility: keyboard nav, focus management on send, ARIA labels.
// Route: GET /chat → Inertia::render('Chat/Index')

import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { BoltIcon, PencilSquareIcon } from '@heroicons/vue/24/solid'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Channel {
    id: number
    channel_type: 'direct' | 'department' | 'participant_idt' | 'broadcast'
    name: string
    unread_count: number
    urgent_unread_count: number
    is_active: boolean
}

interface Message {
    id: number
    channel_id: number
    sender_user_id: number
    sender_name: string
    sender_initials: string
    message_text: string | null
    is_deleted: boolean
    priority: 'standard' | 'urgent'
    sent_at: string
    edited_at: string | null
}

interface DmUser {
    id: number
    name: string
    department: string
    role: string
}

// ── Constants ─────────────────────────────────────────────────────────────────

const CHANNEL_TYPE_LABELS: Record<string, string> = {
    direct: 'Direct Messages',
    participant_idt: 'Participant IDT',
    department: 'Department',
    broadcast: 'Broadcast',
}

const CHANNEL_TYPE_ORDER = ['direct', 'participant_idt', 'department', 'broadcast']

// ── Page props + auth ─────────────────────────────────────────────────────────

const page = usePage()
const me = computed(() => (page.props as any).auth?.user)

// ── State ──────────────────────────────────────────────────────────────────────

const channels = ref<Channel[]>([])
const activeChannel = ref<Channel | null>(null)
const messages = ref<Message[]>([])
const currentPage = ref(1)
const lastPage = ref(1)
const loadingMsgs = ref(false)
const input = ref('')
const isUrgent = ref(false)
const sending = ref(false)
const dmQuery = ref('')
const dmResults = ref<DmUser[]>([])
const dmLoading = ref(false)
const dmHighlight = ref(-1)
const showDmSearch = ref(false)
const messagesEndRef = ref<HTMLDivElement | null>(null)
const inputRef = ref<HTMLTextAreaElement | null>(null)
const echoChannelRef = ref<string | null>(null)

// Prevents the ?channel= auto-select from re-firing after selectChannel
// mutates channels state (which would otherwise retrigger the effect).
const hasAutoSelected = ref(false)

// ── Helpers ────────────────────────────────────────────────────────────────────

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatDate(iso: string): string {
    const d = new Date(iso)
    const today = new Date()
    const yesterday = new Date(today)
    yesterday.setDate(today.getDate() - 1)
    if (d.toDateString() === today.toDateString()) return 'Today'
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function scrollToBottom(behavior: ScrollBehavior = 'smooth') {
    nextTick(() => {
        messagesEndRef.value?.scrollIntoView({ behavior })
    })
}

// ── Grouped channels ──────────────────────────────────────────────────────────

const grouped = computed(() => {
    return CHANNEL_TYPE_ORDER.reduce(
        (acc, type) => {
            const items = channels.value.filter((c) => c.channel_type === type)
            if (items.length > 0) acc[type] = items
            return acc
        },
        {} as Record<string, Channel[]>,
    )
})

// ── Load channel list ──────────────────────────────────────────────────────────

async function loadChannels() {
    try {
        const { data } = await axios.get('/chat/channels')
        channels.value = data.channels ?? []
    } catch {
        // ignore
    }
}

// ── Load messages for active channel ─────────────────────────────────────────

async function loadMessages(channelId: number, pageNum = 1) {
    loadingMsgs.value = true
    try {
        const { data } = await axios.get(`/chat/channels/${channelId}/messages`, {
            params: { page: pageNum },
        })
        const incoming: Message[] = data.messages ?? []
        if (pageNum === 1) {
            // Newest-first from API — reverse to oldest-first for display
            messages.value = incoming.slice().reverse()
        } else {
            // Prepend older messages at the top
            messages.value = [...incoming.slice().reverse(), ...messages.value]
        }
        lastPage.value = data.last_page ?? 1
    } catch {
        // ignore
    } finally {
        loadingMsgs.value = false
    }
}

// ── Select a channel ──────────────────────────────────────────────────────────

async function selectChannel(channel: Channel) {
    activeChannel.value = channel
    messages.value = []
    currentPage.value = 1
    lastPage.value = 1

    await loadMessages(channel.id, 1)
    scrollToBottom('auto')

    // Mark as read
    await axios.post(`/chat/channels/${channel.id}/read`)
    channels.value = channels.value.map((c) =>
        c.id === channel.id ? { ...c, unread_count: 0 } : c,
    )
}

// ── Send message ──────────────────────────────────────────────────────────────

async function sendMessage() {
    if (!activeChannel.value || !input.value.trim() || sending.value) return

    sending.value = true
    const text = input.value.trim()
    input.value = ''

    try {
        const { data } = await axios.post(`/chat/channels/${activeChannel.value.id}/messages`, {
            message_text: text,
            priority: isUrgent.value ? 'urgent' : 'standard',
        })
        // Append immediately from the API response so the sender sees their
        // message right away even if the Reverb WebSocket isn't active.
        // The Echo listener deduplicates by id so no double-append occurs.
        if (data.message) {
            messages.value = [...messages.value, data.message]
            scrollToBottom()
        }
    } catch {
        // Restore input on failure
        input.value = text
    } finally {
        sending.value = false
        inputRef.value?.focus()
    }
}

function handleKeyDown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        sendMessage()
    }
}

// ── Load more (pagination) ────────────────────────────────────────────────────

async function loadMore() {
    if (!activeChannel.value || currentPage.value >= lastPage.value || loadingMsgs.value) return
    const next = currentPage.value + 1
    currentPage.value = next
    await loadMessages(activeChannel.value.id, next)
}

// ── Auto-select channel from URL ?channel= param ─────────────────────────────

watch(channels, (val) => {
    if (hasAutoSelected.value || val.length === 0) return
    const params = new URLSearchParams(window.location.search)
    const chanId = params.get('channel')
    if (!chanId) return
    const target = val.find((c) => c.id === Number(chanId))
    if (target) {
        hasAutoSelected.value = true
        selectChannel(target)
    }
})

// ── DM search — 300ms debounce, min 2 chars ───────────────────────────────────

let dmTimer: ReturnType<typeof setTimeout> | null = null

watch(dmQuery, (val) => {
    if (dmTimer) clearTimeout(dmTimer)
    if (val.trim().length < 2) {
        dmResults.value = []
        dmLoading.value = false
        return
    }
    dmLoading.value = true
    dmTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/chat/users/search', {
                params: { q: val.trim() },
            })
            dmResults.value = data.users ?? []
        } catch {
            dmResults.value = []
        } finally {
            dmLoading.value = false
        }
    }, 300)
})

async function startDm(userId: number) {
    try {
        const { data } = await axios.post(`/chat/direct/${userId}`)
        showDmSearch.value = false
        dmQuery.value = ''
        await loadChannels()
        const ch = { ...data.channel, unread_count: 0 } as Channel
        channels.value = channels.value.some((c) => c.id === ch.id)
            ? channels.value
            : [ch, ...channels.value]
        selectChannel(ch)
    } catch {
        // ignore
    }
}

// ── Reverb real-time subscription ─────────────────────────────────────────────

watch(
    () => activeChannel.value?.id,
    (channelId) => {
        // Leave previous subscription
        if (echoChannelRef.value && window.Echo) {
            window.Echo.leaveChannel(`private-chat.${echoChannelRef.value}`)
        }
        if (!channelId || !window.Echo) {
            echoChannelRef.value = null
            return
        }
        echoChannelRef.value = String(channelId)

        window.Echo.private(`chat.${channelId}`).listen('.chat.message', (raw: unknown) => {
            const payload = raw as Message
            // Deduplicate: sendMessage() already appended the sender's own message
            if (messages.value.some((m) => m.id === payload.id)) return
            messages.value = [...messages.value, payload]
            // Auto-mark read if still viewing this channel
            axios.post(`/chat/channels/${channelId}/read`).catch(() => {})
            scrollToBottom()
        })
    },
)

// ── Subscribe to personal activity channel for unread badge refresh ──────────

onMounted(async () => {
    await loadChannels()

    if (window.Echo && me.value?.id) {
        window.Echo.private(`user.${me.value.id}`).listen('.chat.activity', () => {
            loadChannels()
        })
    }
})

onUnmounted(() => {
    if (window.Echo) {
        if (echoChannelRef.value) {
            window.Echo.leaveChannel(`private-chat.${echoChannelRef.value}`)
        }
        if (me.value?.id) {
            window.Echo.leaveChannel(`private-user.${me.value.id}`)
        }
    }
    if (dmTimer) clearTimeout(dmTimer)
})

// ── Scroll to bottom on initial load ─────────────────────────────────────────

watch(loadingMsgs, (isLoading) => {
    if (!isLoading && messages.value.length > 0) {
        scrollToBottom('auto')
    }
})
</script>

<template>
    <AppShell>
        <Head title="Chat" />

        <div class="flex h-full overflow-hidden bg-white dark:bg-slate-800">
            <!-- ── Left: Channel list ─────────────────────────────────────────── -->
            <aside
                class="w-72 flex flex-col border-r border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 shrink-0"
            >
                <!-- Header -->
                <div
                    class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700"
                >
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                        Messages
                    </h2>
                    <button
                        title="New Direct Message"
                        aria-label="New direct message"
                        class="p-1.5 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 hover:text-slate-700 dark:hover:text-slate-200 transition-colors"
                        @click="showDmSearch = !showDmSearch"
                    >
                        <PencilSquareIcon class="w-4 h-4" aria-hidden="true" />
                    </button>
                </div>

                <!-- DM search -->
                <div
                    v-if="showDmSearch"
                    class="px-3 py-2 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800"
                >
                    <div class="relative">
                        <input
                            v-model="dmQuery"
                            type="text"
                            placeholder="Type 2+ chars to search..."
                            class="w-full text-xs border border-slate-300 dark:border-slate-600 rounded-lg px-2.5 py-1.5 dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            aria-label="Search users for direct message"
                            aria-autocomplete="list"
                            aria-controls="dm-search-results"
                            autofocus
                            @keydown.arrow-down.prevent="dmHighlight = Math.min(dmHighlight + 1, dmResults.length - 1)"
                            @keydown.arrow-up.prevent="dmHighlight = Math.max(dmHighlight - 1, 0)"
                            @keydown.enter.prevent="dmHighlight >= 0 && startDm(dmResults[dmHighlight].id)"
                            @keydown.escape="showDmSearch = false; dmQuery = ''"
                        />
                        <svg
                            v-if="dmLoading"
                            class="absolute right-2.5 top-2 w-3.5 h-3.5 text-slate-400 animate-spin"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                            />
                        </svg>
                    </div>

                    <!-- Results dropdown -->
                    <ul
                        v-if="dmQuery.trim().length >= 2 && !dmLoading"
                        id="dm-search-results"
                        role="listbox"
                        class="mt-1 divide-y divide-slate-100 dark:divide-slate-700 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm overflow-hidden"
                    >
                        <li
                            v-if="dmResults.length === 0"
                            class="px-3 py-2 text-xs text-slate-400 italic"
                        >
                            No users found matching "{{ dmQuery.trim() }}"
                        </li>
                        <li
                            v-for="(u, idx) in dmResults"
                            :key="u.id"
                            role="option"
                            :aria-selected="idx === dmHighlight"
                        >
                            <button
                                :class="[
                                    'w-full text-left px-3 py-2 text-xs transition-colors',
                                    idx === dmHighlight
                                        ? 'bg-blue-50 dark:bg-blue-950/60'
                                        : 'hover:bg-slate-50 dark:hover:bg-slate-700',
                                ]"
                                :aria-label="`Start direct message with ${u.name}`"
                                @click="startDm(u.id)"
                                @mouseenter="dmHighlight = idx"
                            >
                                <span class="font-medium text-slate-800 dark:text-slate-200">{{
                                    u.name
                                }}</span>
                                <span
                                    class="ml-1.5 text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400"
                                >
                                    {{ u.department.replace(/_/g, ' ') }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Channel groups -->
                <nav class="flex-1 overflow-y-auto py-2" aria-label="Chat channels">
                    <template v-for="type in CHANNEL_TYPE_ORDER" :key="type">
                        <div v-if="grouped[type]">
                            <p
                                class="px-4 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500"
                            >
                                {{ CHANNEL_TYPE_LABELS[type] }}
                            </p>
                            <button
                                v-for="ch in grouped[type]"
                                :key="ch.id"
                                :aria-current="activeChannel?.id === ch.id ? 'true' : 'false'"
                                :class="[
                                    'w-full flex items-center gap-2 px-4 py-2 text-left transition-colors',
                                    activeChannel?.id === ch.id
                                        ? 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300'
                                        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800',
                                ]"
                                @click="selectChannel(ch)"
                            >
                                <span class="flex-1 min-w-0 text-xs truncate font-medium">{{
                                    ch.name
                                }}</span>
                                <!-- Urgent badge -->
                                <span
                                    v-if="ch.urgent_unread_count > 0"
                                    class="flex-shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-red-500 text-white"
                                    :aria-label="`${ch.urgent_unread_count} urgent unread`"
                                >
                                    {{ ch.urgent_unread_count }}
                                </span>
                                <!-- Unread badge -->
                                <span
                                    v-else-if="ch.unread_count > 0"
                                    class="flex-shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-blue-500 text-white"
                                    :aria-label="`${ch.unread_count} unread`"
                                >
                                    {{ ch.unread_count }}
                                </span>
                            </button>
                        </div>
                    </template>

                    <!-- Empty state -->
                    <p
                        v-if="channels.length === 0"
                        class="px-4 py-6 text-xs text-slate-400 text-center"
                    >
                        Loading channels...
                    </p>
                </nav>
            </aside>

            <!-- ── Right: Message area ─────────────────────────────────────────── -->
            <main class="flex-1 flex flex-col overflow-hidden">
                <!-- No channel selected -->
                <div v-if="!activeChannel" class="flex-1 flex items-center justify-center">
                    <div class="text-center">
                        <PencilSquareIcon
                            class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3"
                            aria-hidden="true"
                        />
                        <p class="text-sm text-slate-400 dark:text-slate-500">
                            Select a channel to start messaging
                        </p>
                    </div>
                </div>

                <template v-else>
                    <!-- Channel header -->
                    <div
                        class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shrink-0"
                    >
                        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                            {{ activeChannel.name }}
                        </h2>
                        <p class="text-xs text-slate-400 capitalize">
                            {{ activeChannel.channel_type.replace(/_/g, ' ') }}
                        </p>
                    </div>

                    <!-- Messages -->
                    <div
                        class="flex-1 overflow-y-auto px-5 py-4 space-y-4 bg-white dark:bg-slate-800"
                    >
                        <!-- Load more -->
                        <div v-if="currentPage < lastPage" class="text-center">
                            <button
                                :disabled="loadingMsgs"
                                class="text-xs text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50"
                                @click="loadMore"
                            >
                                {{ loadingMsgs ? 'Loading...' : 'Load earlier messages' }}
                            </button>
                        </div>

                        <!-- Date groups + bubbles -->
                        <template v-for="msg in messages" :key="msg.id">
                            <!-- Message bubble -->
                            <div
                                :class="[
                                    'flex gap-2.5 items-start',
                                    msg.priority === 'urgent'
                                        ? 'border-l-2 border-red-500 pl-2'
                                        : '',
                                ]"
                            >
                                <!-- Avatar -->
                                <div
                                    class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                                    :style="{
                                        backgroundColor:
                                            msg.sender_user_id === me?.id ? '#3b82f6' : '#6b7280',
                                    }"
                                    aria-hidden="true"
                                >
                                    {{ msg.sender_initials }}
                                </div>

                                <div class="flex-1 min-w-0">
                                    <!-- Header row -->
                                    <div class="flex items-baseline gap-2 mb-0.5">
                                        <span
                                            class="text-xs font-semibold text-slate-800 dark:text-slate-200"
                                            >{{ msg.sender_name }}</span
                                        >
                                        <span class="text-[10px] text-slate-400">{{
                                            formatTime(msg.sent_at)
                                        }}</span>
                                        <span
                                            v-if="msg.priority === 'urgent'"
                                            class="text-[9px] font-bold uppercase tracking-wide bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 px-1.5 py-0.5 rounded"
                                        >
                                            URGENT
                                        </span>
                                    </div>

                                    <!-- Body -->
                                    <p v-if="msg.is_deleted" class="text-xs italic text-slate-400">
                                        This message was deleted
                                    </p>
                                    <p
                                        v-else
                                        class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap break-words"
                                    >
                                        {{ msg.message_text }}
                                    </p>
                                </div>
                            </div>
                        </template>

                        <!-- Empty state -->
                        <div v-if="!loadingMsgs && messages.length === 0" class="text-center py-8">
                            <p class="text-xs text-slate-400">
                                No messages yet. Be the first to say hello!
                            </p>
                        </div>

                        <!-- Scroll anchor -->
                        <div ref="messagesEndRef" aria-hidden="true"></div>
                    </div>

                    <!-- Input bar -->
                    <div
                        class="px-5 py-3 border-t border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shrink-0"
                    >
                        <div class="flex items-end gap-2">
                            <!-- Urgent toggle -->
                            <button
                                :title="
                                    isUrgent ? 'Urgent: On (click to disable)' : 'Mark as urgent'
                                "
                                :aria-pressed="isUrgent"
                                :class="[
                                    'flex-shrink-0 mb-1 p-1.5 rounded-lg transition-colors',
                                    isUrgent
                                        ? 'bg-red-100 dark:bg-red-900/60 text-red-600 dark:text-red-300'
                                        : 'text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700',
                                ]"
                                aria-label="Toggle urgent message"
                                @click="isUrgent = !isUrgent"
                            >
                                <BoltIcon class="w-4 h-4" aria-hidden="true" />
                            </button>

                            <!-- Textarea -->
                            <textarea
                                ref="inputRef"
                                v-model="input"
                                rows="1"
                                placeholder="Type a message... (Enter to send, Shift+Enter for new line)"
                                aria-label="Message input"
                                class="flex-1 resize-none rounded-xl border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 text-sm py-2 px-3 focus:outline-none focus:ring-2 focus:ring-blue-500 max-h-32 overflow-y-auto"
                                :class="isUrgent ? 'border-red-400 dark:border-red-600' : ''"
                                @keydown="handleKeyDown"
                            ></textarea>

                            <!-- Send button -->
                            <button
                                :disabled="sending || !input.trim()"
                                class="flex-shrink-0 mb-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                                aria-label="Send message"
                                @click="sendMessage"
                            >
                                {{ sending ? '...' : 'Send' }}
                            </button>
                        </div>

                        <p v-if="isUrgent" class="mt-1 text-xs text-red-600 dark:text-red-400 ml-9">
                            Urgent messages create a critical alert for all channel members.
                        </p>
                    </div>
                </template>
            </main>
        </div>
    </AppShell>
</template>
