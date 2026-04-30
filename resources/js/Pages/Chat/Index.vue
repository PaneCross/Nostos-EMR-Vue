<script setup lang="ts">
// ─── Chat/Index.vue (v2) ─────────────────────────────────────────────────────
// End-to-end rebuild for Chat v2.
//
// Two-column layout :
//   Left  (w-72)  : Collapsible channel groups (Specialized, Department,
//                   Broadcast, Participant Care, DMs), each with a
//                   badge-when-collapsed showing unread + mentions.
//   Right         : Active channel header, message list with reactions +
//                   read receipts + @mentions + pin indicator, composer.
//
// Real-time : Reverb private-chat.{channelId} subscriptions for the active
// channel + all 7 v2 events ; polling fallback every 6 s when Reverb is
// unavailable.
//
// Drawers : pinned-list, search, settings (audit timeline), receipts modal,
// reaction popover.
//
// Plan reference : docs/plans/chat_v2_plan.md §9.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import {
    BoltIcon,
    PencilSquareIcon,
    StarIcon as StarSolid,
} from '@heroicons/vue/24/solid'
import {
    BellSlashIcon,
    Cog6ToothIcon,
    MagnifyingGlassIcon,
    StarIcon as StarOutline,
    ChevronDownIcon,
    ChevronRightIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Channel {
    id: number
    channel_type: 'direct' | 'group_dm' | 'department' | 'role_group' | 'participant_idt' | 'broadcast'
    name: string
    description: string | null
    site_wide: boolean
    unread_count: number
    urgent_unread_count: number
    unread_mentions_count: number
    is_active: boolean
    is_muted: boolean
    snoozed_until: string | null
    targets: { roles: string[]; departments: string[]; site_wide: boolean } | null
}

interface Reaction { reaction: string; count: number; my_reaction: boolean }
interface Mention {
    kind: 'user' | 'role' | 'dept' | 'all' | 'unknown'
    mentioned_user_id: number | null
    mentioned_role_code: string | null
    mentioned_department: string | null
    is_at_all: boolean
}

interface Message {
    id: number
    channel_id: number
    sender_user_id: number
    sender_name: string
    sender_initials: string
    message_text: string | null
    is_deleted: boolean
    is_edited: boolean
    priority: 'standard' | 'urgent'
    sent_at: string
    edited_at: string | null
    reactions: Reaction[]
    read_count: number
    total_members: number
    is_pinned: boolean
    mentions: Mention[]
    mentions_me: boolean
    can_edit: boolean
    can_delete: boolean
}

interface DmUser { id: number; name: string; department: string; role: string }

// ── Constants ─────────────────────────────────────────────────────────────────

const CHANNEL_GROUP_ORDER: Array<{ key: string; label: string; types: string[] }> = [
    { key: 'role_group',      label: 'Specialized',          types: ['role_group'] },
    { key: 'department',      label: 'Department',           types: ['department'] },
    { key: 'broadcast',       label: 'Broadcast',            types: ['broadcast'] },
    { key: 'participant_idt', label: 'Participant Care',     types: ['participant_idt'] },
    { key: 'dm',              label: 'Direct Messages',      types: ['direct', 'group_dm'] },
]

const REACTION_PALETTE: Array<{ code: string; emoji: string; label: string }> = [
    { code: 'thumbs_up', emoji: '👍', label: 'Thumbs up' },
    { code: 'check',     emoji: '✅', label: 'Done / agreed' },
    { code: 'eyes',      emoji: '👀', label: 'Looking' },
    { code: 'heart',     emoji: '❤️', label: 'Heart' },
    { code: 'question',  emoji: '❓', label: 'Question' },
]

// ── Page props + auth ─────────────────────────────────────────────────────────

const page = usePage()
const me   = computed(() => (page.props as any).auth?.user)

// ── State ──────────────────────────────────────────────────────────────────────

const channels       = ref<Channel[]>([])
const activeChannel  = ref<Channel | null>(null)
const messages       = ref<Message[]>([])
const currentPage    = ref(1)
const lastPage       = ref(1)
const loadingMsgs    = ref(false)
const input          = ref('')
const isUrgent       = ref(false)
const sending        = ref(false)
const editingId      = ref<number | null>(null)

// Channel-create modals + drawers
const showCreateRoleGroup = ref(false)
const showCreateGroupDm   = ref(false)
const showSettings        = ref(false)
const showPinPanel        = ref(false)
const showSearch          = ref(false)
const showReceipts        = ref<number | null>(null) // message id
const showPinOverride     = ref<number | null>(null) // message id pending override

// DM search (existing)
const dmQuery        = ref('')
const dmResults      = ref<DmUser[]>([])
const dmLoading      = ref(false)
const showDmSearch   = ref(false)

// Group-collapse state, persisted in localStorage.
const collapsedGroups = ref<Record<string, boolean>>({})
const COLLAPSED_KEY = 'nostos_chat_collapsed_groups'

// Search state
const searchQuery = ref('')
const searchResults = ref<any[]>([])

// Pin panel state
const pinList = ref<any[]>([])

// Settings drawer state
const settings = ref<any>(null)

// Receipts modal state
const receipts = ref<any>(null)

const messagesEndRef = ref<HTMLDivElement | null>(null)
const inputRef       = ref<HTMLTextAreaElement | null>(null)
const echoChannelRef = ref<string | null>(null)

// Polling fallback : if Reverb isn't available, refresh on a timer.
let pollTimer: ReturnType<typeof setInterval> | null = null

// Prevents the auto-select from re-firing.
const hasAutoSelected = ref(false)

// ── Helpers ────────────────────────────────────────────────────────────────────

function formatTime(iso: string): string {
    if (! iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function scrollToBottom(behavior: ScrollBehavior = 'smooth') {
    nextTick(() => {
        messagesEndRef.value?.scrollIntoView({ behavior })
    })
}

function loadCollapsedFromLocal() {
    try {
        const raw = localStorage.getItem(COLLAPSED_KEY)
        if (raw) collapsedGroups.value = JSON.parse(raw)
    } catch { /* ignore */ }
}

function persistCollapsed() {
    try { localStorage.setItem(COLLAPSED_KEY, JSON.stringify(collapsedGroups.value)) } catch { /* ignore */ }
}

function toggleGroup(key: string) {
    collapsedGroups.value[key] = ! collapsedGroups.value[key]
    persistCollapsed()
}

function emojiFor(code: string): string {
    return REACTION_PALETTE.find(p => p.code === code)?.emoji ?? '?'
}

// ── Grouped channels ──────────────────────────────────────────────────────────

const grouped = computed(() => {
    return CHANNEL_GROUP_ORDER.map(group => ({
        ...group,
        items: channels.value.filter(c => group.types.includes(c.channel_type)),
    }))
})

function groupBadge(items: Channel[]): { unread: number; mentions: number; urgent: number } {
    return items.reduce(
        (acc, c) => ({
            unread:   acc.unread + c.unread_count,
            mentions: acc.mentions + c.unread_mentions_count,
            urgent:   acc.urgent + c.urgent_unread_count,
        }),
        { unread: 0, mentions: 0, urgent: 0 },
    )
}

// ── API ────────────────────────────────────────────────────────────────────────

async function loadChannels() {
    const r = await axios.get('/chat/channels')
    channels.value = r.data.channels ?? []
}

async function loadMessages(channelId: number, page = 1) {
    loadingMsgs.value = true
    try {
        const r = await axios.get(`/chat/channels/${channelId}/messages`, { params: { page } })
        // Server returns newest-first ; we render oldest-first in the column.
        messages.value = (r.data.messages ?? []).slice().reverse()
        currentPage.value = r.data.current_page ?? 1
        lastPage.value    = r.data.last_page ?? 1
        scrollToBottom('auto')
    } finally {
        loadingMsgs.value = false
    }
}

async function selectChannel(c: Channel) {
    activeChannel.value = c
    editingId.value = null
    showSearch.value = false
    showPinPanel.value = false
    showSettings.value = false
    await loadMessages(c.id)
    await axios.post(`/chat/channels/${c.id}/read`)
    await loadChannels()
    bindRealtime(c.id)
}

async function send() {
    if (! input.value.trim() || ! activeChannel.value || sending.value) return
    sending.value = true
    try {
        const payload = {
            message_text: input.value,
            priority: isUrgent.value ? 'urgent' : 'standard',
        }
        if (editingId.value) {
            await axios.patch(
                `/chat/channels/${activeChannel.value.id}/messages/${editingId.value}`,
                { message_text: input.value },
            )
            editingId.value = null
        } else {
            await axios.post(`/chat/channels/${activeChannel.value.id}/messages`, payload)
        }
        input.value = ''
        isUrgent.value = false
        await loadMessages(activeChannel.value.id, 1)
    } catch (e: any) {
        if (e.response?.status === 422) {
            alert(e.response?.data?.message || 'Message rejected.')
        }
    } finally {
        sending.value = false
        inputRef.value?.focus()
    }
}

async function toggleReaction(messageId: number, code: string) {
    if (! activeChannel.value) return
    await axios.post(
        `/chat/channels/${activeChannel.value.id}/messages/${messageId}/react`,
        { reaction: code },
    )
    await loadMessages(activeChannel.value.id, currentPage.value)
}

async function pin(messageId: number, override = false) {
    if (! activeChannel.value) return
    try {
        await axios.post(
            `/chat/channels/${activeChannel.value.id}/messages/${messageId}/pin`,
            override ? { override: true } : {},
        )
        await loadMessages(activeChannel.value.id, currentPage.value)
        showPinOverride.value = null
    } catch (e: any) {
        if (e.response?.status === 422 && /pin limit/i.test(e.response?.data?.message || '')) {
            showPinOverride.value = messageId
            return
        }
        alert(e.response?.data?.message || 'Pin failed.')
    }
}

async function unpin(messageId: number) {
    if (! activeChannel.value) return
    await axios.delete(`/chat/channels/${activeChannel.value.id}/messages/${messageId}/pin`)
    await loadMessages(activeChannel.value.id, currentPage.value)
}

async function deleteMessage(messageId: number) {
    if (! activeChannel.value) return
    if (! confirm('Delete this message? Soft-delete only ; original text is preserved for audit.')) return
    await axios.delete(`/chat/channels/${activeChannel.value.id}/messages/${messageId}`)
    await loadMessages(activeChannel.value.id, currentPage.value)
}

function startEdit(m: Message) {
    editingId.value = m.id
    input.value = m.message_text ?? ''
    inputRef.value?.focus()
}

function cancelEdit() {
    editingId.value = null
    input.value = ''
}

async function markVisible(messageId: number) {
    if (! activeChannel.value) return
    try {
        await axios.post(`/chat/channels/${activeChannel.value.id}/messages/${messageId}/read`)
    } catch { /* idempotent server-side */ }
}

async function openReceipts(messageId: number) {
    if (! activeChannel.value) return
    showReceipts.value = messageId
    const r = await axios.get(`/chat/channels/${activeChannel.value.id}/messages/${messageId}/details`)
    receipts.value = r.data
}

async function openPinPanel() {
    if (! activeChannel.value) return
    showPinPanel.value = true
    const r = await axios.get(`/chat/channels/${activeChannel.value.id}/pins`)
    pinList.value = r.data.pins ?? []
}

async function openSettings() {
    if (! activeChannel.value) return
    showSettings.value = true
    const r = await axios.get(`/chat/channels/${activeChannel.value.id}/settings`)
    settings.value = r.data
}

async function runSearch() {
    if (! activeChannel.value || searchQuery.value.trim().length < 2) {
        searchResults.value = []
        return
    }
    const r = await axios.get(`/chat/channels/${activeChannel.value.id}/search`, { params: { q: searchQuery.value } })
    searchResults.value = r.data.matches ?? []
}

async function muteChannel() {
    if (! activeChannel.value) return
    await axios.post(`/chat/channels/${activeChannel.value.id}/mute`)
    await loadChannels()
}

async function unmuteChannel() {
    if (! activeChannel.value) return
    await axios.delete(`/chat/channels/${activeChannel.value.id}/mute`)
    await loadChannels()
}

// ── Real-time / polling ───────────────────────────────────────────────────────

function bindRealtime(channelId: number) {
    if (echoChannelRef.value) {
        ;(window as any).Echo?.leave?.(echoChannelRef.value)
        echoChannelRef.value = null
    }
    const echo = (window as any).Echo
    if (! echo) {
        startPolling()
        return
    }
    stopPolling()
    const name = `chat.${channelId}`
    echoChannelRef.value = name
    const ch = echo.private(name)
    ch.listen('.chat.message',                () => activeChannel.value && loadMessages(activeChannel.value.id, 1))
    ch.listen('.chat.message.reacted',        () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.message.read',           () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.message.edited',         () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.message.deleted',        () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.message.pinned',         () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.message.unpinned',       () => activeChannel.value && loadMessages(activeChannel.value.id, currentPage.value))
    ch.listen('.chat.channel.members_changed',() => loadChannels())
}

function startPolling() {
    if (pollTimer) return
    pollTimer = setInterval(async () => {
        if (activeChannel.value) {
            await loadMessages(activeChannel.value.id, 1)
        }
        await loadChannels()
    }, 6000)
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

// ── Mount ──────────────────────────────────────────────────────────────────────

onMounted(async () => {
    loadCollapsedFromLocal()
    await loadChannels()
    if (! hasAutoSelected.value && channels.value.length > 0) {
        const first = grouped.value.flatMap(g => g.items)[0]
        if (first) await selectChannel(first)
        hasAutoSelected.value = true
    }
})

onUnmounted(() => {
    if (echoChannelRef.value) (window as any).Echo?.leave?.(echoChannelRef.value)
    stopPolling()
})

// ── Channel-create flows ──────────────────────────────────────────────────────

const newRoleGroup = ref<{
    name: string; description: string;
    job_title_codes: string;  // comma-separated
    departments: string[];
    site_wide: boolean;
}>({ name: '', description: '', job_title_codes: '', departments: [], site_wide: false })

async function createRoleGroup() {
    if (! confirm('All future role-holders will see the entire history of this conversation when they are added. Confirm this is appropriate for a clinical group chat.')) return
    try {
        await axios.post('/chat/role-group-channels', {
            name: newRoleGroup.value.name,
            description: newRoleGroup.value.description || null,
            job_title_codes: newRoleGroup.value.job_title_codes.split(',').map(s => s.trim()).filter(Boolean),
            departments: newRoleGroup.value.departments,
            site_wide: newRoleGroup.value.site_wide,
        })
        showCreateRoleGroup.value = false
        newRoleGroup.value = { name: '', description: '', job_title_codes: '', departments: [], site_wide: false }
        await loadChannels()
    } catch (e: any) {
        alert(e.response?.data?.message || 'Failed to create channel.')
    }
}

const newGroupDm = ref<{ name: string; member_user_ids: number[] }>({ name: '', member_user_ids: [] })

async function createGroupDm() {
    try {
        await axios.post('/chat/group-dm-channels', {
            name: newGroupDm.value.name || null,
            member_user_ids: newGroupDm.value.member_user_ids,
        })
        showCreateGroupDm.value = false
        newGroupDm.value = { name: '', member_user_ids: [] }
        await loadChannels()
    } catch (e: any) {
        alert(e.response?.data?.message || 'Failed to create group DM.')
    }
}

watch(dmQuery, async (q) => {
    if (q.trim().length < 2) { dmResults.value = []; return }
    dmLoading.value = true
    try {
        const r = await axios.get('/chat/users/search', { params: { q } })
        dmResults.value = r.data.users ?? []
    } finally { dmLoading.value = false }
})

async function startDirect(userId: number) {
    const r = await axios.post(`/chat/direct/${userId}`)
    showDmSearch.value = false
    dmQuery.value = ''
    await loadChannels()
    const created = channels.value.find(c => c.id === r.data.channel.id)
    if (created) await selectChannel(created)
}

// IntersectionObserver for read receipts. Registered as a ref-driven
// onMounted hook on each MessageRow via a watcher-style binding below.
function observeForRead(el: HTMLElement | null, messageId: number) {
    if (! el) return
    let timer: any
    const obs = new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (entry.intersectionRatio > 0.5) {
                timer = setTimeout(() => {
                    markVisible(messageId)
                    obs.disconnect()
                }, 500)
            } else if (timer) { clearTimeout(timer); timer = null }
        }
    }, { threshold: 0.5 })
    obs.observe(el)
}
</script>

<template>
    <Head title="Chat" />
    <AppShell>
        <div class="flex h-[calc(100vh-3.5rem)] bg-slate-50 dark:bg-slate-900">

            <!-- ── Channel list (left) ─────────────────────────────────────── -->
            <aside class="w-72 shrink-0 border-r border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex flex-col">
                <div class="p-3 border-b border-slate-200 dark:border-slate-700 flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100 flex-1">Chats</h2>
                    <button
                        @click="showDmSearch = ! showDmSearch"
                        class="rounded p-1 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                        title="New direct message"
                    ><PencilSquareIcon class="w-4 h-4" /></button>
                    <button
                        v-if="me?.role === 'admin' || me?.is_super_admin || me?.department === 'executive'"
                        @click="showCreateRoleGroup = true"
                        class="rounded px-2 py-1 text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700"
                        title="Create specialized chat"
                    >+ Specialized</button>
                </div>

                <!-- DM user search -->
                <div v-if="showDmSearch" class="p-3 border-b border-slate-200 dark:border-slate-700 space-y-2">
                    <input
                        v-model="dmQuery"
                        placeholder="Find a user..."
                        class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-2 py-1"
                    />
                    <button
                        @click="showCreateGroupDm = true; showDmSearch = false"
                        class="w-full text-left text-xs text-indigo-700 dark:text-indigo-300 hover:underline"
                    >+ New group DM (multiple users)</button>
                    <div v-if="dmLoading" class="text-xs text-slate-500">Searching...</div>
                    <ul v-else class="space-y-1">
                        <li v-for="u in dmResults" :key="u.id">
                            <button
                                @click="startDirect(u.id)"
                                class="w-full text-left rounded px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-700 text-sm text-slate-800 dark:text-slate-200"
                            >{{ u.name }}<span class="text-xs text-slate-500 dark:text-slate-400 ml-1">{{ u.department }}</span></button>
                        </li>
                    </ul>
                </div>

                <!-- Channel groups -->
                <div class="flex-1 overflow-y-auto">
                    <template v-for="group in grouped" :key="group.key">
                        <div v-if="group.items.length > 0" class="border-b border-slate-100 dark:border-slate-700/50">
                            <button
                                @click="toggleGroup(group.key)"
                                class="w-full px-3 py-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700/30"
                            >
                                <ChevronDownIcon v-if="! collapsedGroups[group.key]" class="w-3 h-3" />
                                <ChevronRightIcon v-else class="w-3 h-3" />
                                <span class="flex-1 text-left">{{ group.label }}</span>
                                <span
                                    v-if="collapsedGroups[group.key] && (groupBadge(group.items).unread > 0 || groupBadge(group.items).mentions > 0)"
                                    class="inline-flex items-center gap-1"
                                >
                                    <span v-if="groupBadge(group.items).mentions > 0" class="rounded bg-amber-500 text-white text-[10px] px-1.5 font-bold">@{{ groupBadge(group.items).mentions }}</span>
                                    <span v-if="groupBadge(group.items).unread > 0" class="rounded bg-indigo-600 text-white text-[10px] px-1.5 font-bold">{{ groupBadge(group.items).unread }}</span>
                                </span>
                            </button>
                            <ul v-if="! collapsedGroups[group.key]" class="pb-1">
                                <li v-for="c in group.items" :key="c.id">
                                    <button
                                        @click="selectChannel(c)"
                                        :class="[
                                            'w-full text-left px-3 py-1.5 text-sm flex items-center gap-2 hover:bg-slate-50 dark:hover:bg-slate-700/40',
                                            activeChannel?.id === c.id ? 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-900 dark:text-indigo-200 font-medium' : 'text-slate-700 dark:text-slate-300'
                                        ]"
                                    >
                                        <span class="flex-1 truncate">{{ c.name }}</span>
                                        <BellSlashIcon v-if="c.is_muted" class="w-3 h-3 text-slate-400" title="Muted" />
                                        <span v-if="c.unread_mentions_count > 0" class="rounded bg-amber-500 text-white text-[10px] px-1 font-bold">@{{ c.unread_mentions_count }}</span>
                                        <span v-else-if="c.unread_count > 0" class="rounded bg-indigo-600 text-white text-[10px] px-1 font-bold">{{ c.unread_count }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </template>
                </div>
            </aside>

            <!-- ── Active channel (right) ────────────────────────────────── -->
            <main class="flex-1 flex flex-col">
                <div v-if="! activeChannel" class="flex-1 flex items-center justify-center text-slate-500 dark:text-slate-400 text-sm">
                    Select a channel to begin.
                </div>

                <template v-else>
                    <!-- Header -->
                    <header class="px-4 py-2.5 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <h1 class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{{ activeChannel.name }}</h1>
                            <p v-if="activeChannel.description" class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ activeChannel.description }}</p>
                        </div>
                        <button @click="showSearch = ! showSearch" class="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400" title="Search">
                            <MagnifyingGlassIcon class="w-4 h-4" />
                        </button>
                        <button @click="openPinPanel" class="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400" title="Pinned messages">
                            <StarOutline class="w-4 h-4" />
                        </button>
                        <button v-if="! activeChannel.is_muted" @click="muteChannel" class="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400" title="Mute notifications">
                            <BellSlashIcon class="w-4 h-4" />
                        </button>
                        <button v-else @click="unmuteChannel" class="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-amber-600 dark:text-amber-400" title="Muted (click to unmute)">
                            <BellSlashIcon class="w-4 h-4" />
                        </button>
                        <button @click="openSettings" class="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400" title="Channel settings + audit timeline">
                            <Cog6ToothIcon class="w-4 h-4" />
                        </button>
                    </header>

                    <!-- Search panel -->
                    <div v-if="showSearch" class="px-4 py-2 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 space-y-2">
                        <input
                            v-model="searchQuery"
                            @keyup.enter="runSearch"
                            placeholder="Search this channel..."
                            class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-1.5"
                        />
                        <ul v-if="searchResults.length > 0" class="max-h-40 overflow-y-auto space-y-1 text-xs">
                            <li v-for="m in searchResults" :key="m.id" class="bg-white dark:bg-slate-800 rounded p-2 border border-slate-200 dark:border-slate-700">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ m.sender_name }} <span class="text-slate-400">{{ formatTime(m.sent_at) }}</span></div>
                                <div class="text-slate-700 dark:text-slate-300 truncate">{{ m.message_text }}</div>
                            </li>
                        </ul>
                    </div>

                    <!-- Messages -->
                    <div class="flex-1 overflow-y-auto p-4 space-y-3">
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            :ref="el => observeForRead(el as HTMLElement, m.id)"
                            :class="['rounded-lg border', m.mentions_me ? 'border-l-4 border-l-amber-500' : 'border-l border-l-transparent', m.priority === 'urgent' ? 'border-red-300 dark:border-red-800' : 'border-slate-200 dark:border-slate-700', 'bg-white dark:bg-slate-800 p-3']"
                        >
                            <div class="flex items-start gap-2">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 flex items-center justify-center text-xs font-semibold shrink-0">
                                    {{ m.sender_initials }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 text-xs flex-wrap">
                                        <span class="font-semibold text-slate-900 dark:text-slate-100">{{ m.sender_name }}</span>
                                        <span class="text-slate-400">{{ formatTime(m.sent_at) }}</span>
                                        <span v-if="m.is_edited" class="text-slate-400 italic">(edited)</span>
                                        <span v-if="m.is_pinned" class="text-amber-600 dark:text-amber-400 inline-flex items-center gap-0.5"><StarSolid class="w-3 h-3" />Pinned</span>
                                        <span v-if="m.priority === 'urgent'" class="rounded bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-1.5 text-[10px] font-bold uppercase tracking-wide">Urgent</span>
                                        <span v-if="m.mentions_me" class="rounded bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 px-1.5 text-[10px] font-bold">Mentioned you</span>
                                    </div>
                                    <p v-if="m.is_deleted" class="mt-1 text-sm italic text-slate-400 dark:text-slate-500">This message was deleted.</p>
                                    <p v-else class="mt-1 text-sm text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ m.message_text }}</p>

                                    <!-- Reactions -->
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                        <button
                                            v-for="r in m.reactions"
                                            :key="r.reaction"
                                            @click="toggleReaction(m.id, r.reaction)"
                                            :class="[
                                                'rounded px-1.5 py-0.5 text-xs flex items-center gap-1 border',
                                                r.my_reaction
                                                    ? 'bg-indigo-50 dark:bg-indigo-900/30 border-indigo-300 dark:border-indigo-700 text-indigo-800 dark:text-indigo-200'
                                                    : 'bg-slate-50 dark:bg-slate-700/50 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300',
                                            ]"
                                        >
                                            <span>{{ emojiFor(r.reaction) }}</span>
                                            <span class="font-mono text-[10px]">{{ r.count }}</span>
                                        </button>
                                        <details class="inline-block">
                                            <summary class="rounded px-1.5 py-0.5 text-xs cursor-pointer text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 list-none">+ react</summary>
                                            <div class="absolute z-10 mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded shadow-lg p-1 flex gap-1">
                                                <button
                                                    v-for="p in REACTION_PALETTE"
                                                    :key="p.code"
                                                    @click="toggleReaction(m.id, p.code)"
                                                    :title="p.label"
                                                    class="rounded p-1 hover:bg-slate-100 dark:hover:bg-slate-700 text-base"
                                                >{{ p.emoji }}</button>
                                            </div>
                                        </details>
                                        <button @click="openReceipts(m.id)" class="ml-2 text-[10px] text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 hover:underline">
                                            👁 {{ m.read_count }}/{{ m.total_members }}
                                        </button>
                                    </div>
                                </div>
                                <details class="relative">
                                    <summary class="list-none cursor-pointer text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 px-1">⋯</summary>
                                    <div class="absolute right-0 z-10 mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded shadow-lg w-40 py-1">
                                        <button v-if="m.can_edit && ! m.is_deleted" @click="startEdit(m)" class="w-full text-left px-3 py-1 text-xs text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">Edit (5m window)</button>
                                        <button v-if="! m.is_pinned && ! m.is_deleted" @click="pin(m.id)" class="w-full text-left px-3 py-1 text-xs text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">Pin to channel</button>
                                        <button v-else-if="m.is_pinned" @click="unpin(m.id)" class="w-full text-left px-3 py-1 text-xs text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">Unpin</button>
                                        <button v-if="m.can_delete && ! m.is_deleted" @click="deleteMessage(m.id)" class="w-full text-left px-3 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30">Delete</button>
                                    </div>
                                </details>
                            </div>
                        </div>
                        <div ref="messagesEndRef" />
                    </div>

                    <!-- Composer -->
                    <div class="border-t border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3">
                        <div v-if="editingId" class="text-xs text-amber-700 dark:text-amber-300 mb-1">
                            Editing message (5-minute window) <button @click="cancelEdit" class="text-slate-500 hover:underline">cancel</button>
                        </div>
                        <div class="flex items-end gap-2">
                            <textarea
                                ref="inputRef"
                                v-model="input"
                                @keydown.enter.prevent="! $event.shiftKey && send()"
                                placeholder="Type a message... (use @ to mention. Shift+Enter for newline.)"
                                rows="2"
                                class="flex-1 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2 resize-none"
                            />
                            <button
                                @click="isUrgent = ! isUrgent"
                                :class="['p-2 rounded border', isUrgent ? 'bg-red-50 dark:bg-red-900/40 border-red-300 dark:border-red-700 text-red-700 dark:text-red-300' : 'border-slate-300 dark:border-slate-600 text-slate-500 dark:text-slate-400']"
                                title="Mark as urgent"
                            >
                                <BoltIcon class="w-4 h-4" />
                            </button>
                            <button
                                @click="send"
                                :disabled="sending || ! input.trim()"
                                class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                            >{{ sending ? 'Sending...' : (editingId ? 'Save' : 'Send') }}</button>
                        </div>
                    </div>
                </template>
            </main>
        </div>

        <!-- ── Modals + drawers (kept inline for v1 simplicity) ────────────────── -->

        <!-- Create role-group -->
        <div v-if="showCreateRoleGroup" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 space-y-4">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Create specialized chat</h3>
                <p class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/40 rounded p-3 border border-amber-200 dark:border-amber-700">
                    <strong>Heads up :</strong> all future role-holders will see the entire history of this conversation when they're auto-added. Confirm this is appropriate for a clinical group chat.
                </p>
                <input v-model="newRoleGroup.name" placeholder="Channel name" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm px-3 py-2" />
                <input v-model="newRoleGroup.description" placeholder="Description (optional)" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm px-3 py-2" />
                <input v-model="newRoleGroup.job_title_codes" placeholder="JobTitle codes, comma-separated (e.g. rn,lpn)" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm px-3 py-2" />
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" v-model="newRoleGroup.site_wide" /> Site-wide (all departments)
                </label>
                <select v-if="! newRoleGroup.site_wide" v-model="newRoleGroup.departments" multiple class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm px-3 py-2 h-32">
                    <option value="primary_care">Primary Care</option>
                    <option value="therapies">Therapies</option>
                    <option value="social_work">Social Work</option>
                    <option value="behavioral_health">Behavioral Health</option>
                    <option value="dietary">Dietary</option>
                    <option value="activities">Activities</option>
                    <option value="home_care">Home Care</option>
                    <option value="transportation">Transportation</option>
                    <option value="pharmacy">Pharmacy</option>
                    <option value="idt">IDT</option>
                    <option value="enrollment">Enrollment</option>
                    <option value="finance">Finance</option>
                    <option value="qa_compliance">QA / Compliance</option>
                    <option value="it_admin">IT Admin</option>
                </select>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showCreateRoleGroup = false" class="px-3 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200">Cancel</button>
                    <button @click="createRoleGroup" class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white">Create channel</button>
                </div>
            </div>
        </div>

        <!-- Pin override dialog -->
        <div v-if="showPinOverride" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full p-5 space-y-3">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Channel at pin limit</h3>
                <p class="text-sm text-slate-700 dark:text-slate-300">
                    This channel has reached its 50-pin limit. Unpin something first, or override to add this pin anyway. Override is audit-logged.
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showPinOverride = null" class="px-3 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200">Cancel</button>
                    <button @click="pin(showPinOverride!, true)" class="px-3 py-1.5 text-sm rounded bg-amber-600 text-white">Pin anyway</button>
                </div>
            </div>
        </div>

        <!-- Receipts modal -->
        <div v-if="showReceipts && receipts" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="showReceipts = null">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full p-5 space-y-3 max-h-[80vh] overflow-y-auto">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Message details</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Sent {{ receipts.sent_at && formatTime(receipts.sent_at) }}</p>
                <div>
                    <h4 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 mb-1">Read by ({{ receipts.reads.length }} of {{ receipts.total_members }})</h4>
                    <ul class="text-xs space-y-0.5">
                        <li v-for="r in receipts.reads" :key="r.user_id" class="flex justify-between"><span>{{ r.name }}</span><span class="text-slate-400">{{ formatTime(r.read_at) }}</span></li>
                    </ul>
                </div>
                <div v-if="receipts.reactions.length > 0">
                    <h4 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 mb-1">Reactions</h4>
                    <ul class="text-xs space-y-0.5">
                        <li v-for="r in receipts.reactions" :key="r.user_id + r.reaction" class="flex justify-between"><span>{{ r.name }} {{ emojiFor(r.reaction) }}</span><span class="text-slate-400">{{ formatTime(r.reacted_at) }}</span></li>
                    </ul>
                </div>
                <button @click="showReceipts = null" class="px-3 py-1.5 text-sm rounded bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 w-full">Close</button>
            </div>
        </div>

        <!-- Pin panel -->
        <div v-if="showPinPanel" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="showPinPanel = false">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 space-y-3 max-h-[80vh] overflow-y-auto">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Pinned messages</h3>
                <p v-if="pinList.length === 0" class="text-sm text-slate-500 dark:text-slate-400">No pinned messages.</p>
                <ul class="space-y-2">
                    <li v-for="p in pinList" :key="p.message_id" class="rounded border border-slate-200 dark:border-slate-700 p-2 bg-slate-50 dark:bg-slate-900/40">
                        <div class="text-xs text-slate-500">{{ p.sender_name }} · {{ formatTime(p.sent_at) }} · pinned by {{ p.pinned_by }}</div>
                        <p class="text-sm text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ p.message_text || '(deleted)' }}</p>
                    </li>
                </ul>
                <button @click="showPinPanel = false" class="px-3 py-1.5 text-sm rounded bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 w-full">Close</button>
            </div>
        </div>

        <!-- Settings drawer with audit timeline -->
        <div v-if="showSettings && settings" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="showSettings = false">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 space-y-4 max-h-[80vh] overflow-y-auto">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Channel settings : {{ settings.channel.name }}</h3>
                <p v-if="settings.channel.description" class="text-sm text-slate-600 dark:text-slate-400">{{ settings.channel.description }}</p>
                <div v-if="settings.channel.targets">
                    <h4 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 mb-1">Targets</h4>
                    <p class="text-xs text-slate-700 dark:text-slate-300">Roles : {{ settings.channel.targets.roles.join(', ') || '(none)' }}</p>
                    <p class="text-xs text-slate-700 dark:text-slate-300">Departments : {{ settings.channel.targets.site_wide ? 'site-wide' : settings.channel.targets.departments.join(', ') || '(none)' }}</p>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 mb-1">Members ({{ settings.channel.members.length }})</h4>
                    <ul class="text-xs space-y-0.5 max-h-32 overflow-y-auto">
                        <li v-for="m in settings.channel.members" :key="m.user_id" class="flex justify-between">
                            <span>{{ m.name }} <span class="text-slate-400">{{ m.job_title }} / {{ m.department }}</span></span>
                            <span class="text-slate-400">{{ formatTime(m.joined_at) }}</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 mb-1">Audit timeline</h4>
                    <ol class="text-xs space-y-1 max-h-40 overflow-y-auto">
                        <li v-for="(e, idx) in settings.audit_timeline" :key="idx" class="border-l-2 border-slate-300 dark:border-slate-600 pl-2">
                            <div class="text-slate-700 dark:text-slate-300">{{ e.description }}</div>
                            <div class="text-slate-400">{{ formatTime(e.at) }}</div>
                        </li>
                    </ol>
                </div>
                <button @click="showSettings = false" class="px-3 py-1.5 text-sm rounded bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 w-full">Close</button>
            </div>
        </div>

    </AppShell>
</template>
