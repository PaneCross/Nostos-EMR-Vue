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

import { ref, computed, onMounted, onUnmounted, watch, nextTick, type Component } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import {
    BoltIcon,
    PencilSquareIcon,
    StarIcon as StarSolid,
    HandThumbUpIcon as HandThumbUpSolid,
    CheckCircleIcon as CheckCircleSolid,
    EyeIcon as EyeSolid,
    HeartIcon as HeartSolid,
    QuestionMarkCircleIcon as QuestionMarkCircleSolid,
} from '@heroicons/vue/24/solid'
import {
    BellSlashIcon,
    Cog6ToothIcon,
    MagnifyingGlassIcon,
    StarIcon as StarOutline,
    ChevronDownIcon,
    ChevronRightIcon,
    HandThumbUpIcon as HandThumbUpOutline,
    CheckCircleIcon as CheckCircleOutline,
    EyeIcon as EyeOutline,
    HeartIcon as HeartOutline,
    QuestionMarkCircleIcon as QuestionMarkCircleOutline,
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

// Reactions use Heroicons (on theme with the rest of the app). The "active"
// state on a reaction is the solid variant ; the resting palette is outline.
// Each entry pairs the semantic code with both icon variants and a tooltip.
interface ReactionPaletteItem {
    code: string
    label: string
    iconOutline: Component
    iconSolid: Component
}
const REACTION_PALETTE: ReactionPaletteItem[] = [
    { code: 'thumbs_up', label: 'Thumbs up',      iconOutline: HandThumbUpOutline,        iconSolid: HandThumbUpSolid },
    { code: 'check',     label: 'Done / agreed', iconOutline: CheckCircleOutline,        iconSolid: CheckCircleSolid },
    { code: 'eyes',      label: 'Looking',       iconOutline: EyeOutline,                iconSolid: EyeSolid },
    { code: 'heart',     label: 'Heart',         iconOutline: HeartOutline,              iconSolid: HeartSolid },
    { code: 'question',  label: 'Question',      iconOutline: QuestionMarkCircleOutline, iconSolid: QuestionMarkCircleSolid },
]

function paletteFor(code: string): ReactionPaletteItem | undefined {
    return REACTION_PALETTE.find(p => p.code === code)
}

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

// Per-channel draft state. Switching channels saves the current channel's
// composer state and restores the destination's. Persisted to localStorage
// so the drafts survive a page refresh — same pattern Slack uses.
interface ChannelDraft { text: string; urgent: boolean; editingId: number | null }
const drafts = ref<Record<number, ChannelDraft>>({})
const DRAFTS_KEY = 'nostos_chat_drafts'

function loadDraftsFromLocal() {
    try {
        const raw = localStorage.getItem(DRAFTS_KEY)
        if (raw) drafts.value = JSON.parse(raw)
    } catch { /* ignore */ }
}

function persistDrafts() {
    try { localStorage.setItem(DRAFTS_KEY, JSON.stringify(drafts.value)) } catch { /* ignore */ }
}

function captureCurrentDraft() {
    if (! activeChannel.value) return
    const id = activeChannel.value.id
    if (input.value.trim() === '' && editingId.value === null) {
        // Empty draft : clean up the entry so we don't accumulate stale keys.
        if (drafts.value[id]) {
            delete drafts.value[id]
            persistDrafts()
        }
        return
    }
    drafts.value[id] = {
        text: input.value,
        urgent: isUrgent.value,
        editingId: editingId.value,
    }
    persistDrafts()
}

function restoreDraftFor(channelId: number) {
    const d = drafts.value[channelId]
    if (d) {
        input.value = d.text
        isUrgent.value = d.urgent
        editingId.value = d.editingId
    } else {
        input.value = ''
        isUrgent.value = false
        editingId.value = null
    }
}

// Channel-create modals + drawers
const showCreateRoleGroup = ref(false)
const showCreateGroupDm   = ref(false)
const showSettings        = ref(false)
const showPinPanel        = ref(false)
const showSearch          = ref(false)
const showReceipts        = ref<number | null>(null) // message id
const showPinOverride     = ref<number | null>(null) // message id pending override

// Tenant-level lookup data, loaded lazily when the relevant modal opens.
interface JobTitleOption { code: string; label: string }
interface DepartmentOption { slug: string; label: string }
interface MemberOption { id: number; name: string; handle: string; department: string; job_title: string | null }

const jobTitleOptions    = ref<JobTitleOption[]>([])
const departmentOptions  = ref<DepartmentOption[]>([])
const channelMembers     = ref<MemberOption[]>([])    // members of activeChannel
const tenantUserSearch   = ref<DmUser[]>([])           // tenant-wide for group-DM picker

async function loadJobTitles() {
    if (jobTitleOptions.value.length > 0) return
    const r = await axios.get('/chat/job-titles')
    jobTitleOptions.value = r.data.job_titles ?? []
}
async function loadDepartments() {
    if (departmentOptions.value.length > 0) return
    const r = await axios.get('/chat/departments')
    departmentOptions.value = r.data.departments ?? []
}
async function loadChannelMembers(channelId: number) {
    const r = await axios.get(`/chat/channels/${channelId}/members`)
    channelMembers.value = r.data.members ?? []
}

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

// ── @mention typeahead state ──────────────────────────────────────────────────
// When the user types '@' followed by characters, we open a popover above the
// composer with matching options. Selection inserts the full handle in place
// of the partial, and resumes typing. See selectMention() for the insertion
// math.
const mentionOpen     = ref(false)
const mentionQuery    = ref('')           // chars typed AFTER the @
const mentionStart    = ref(-1)           // index of the '@' in input
const mentionHighlight = ref(0)           // keyboard nav index

interface MentionOption {
    kind: 'user' | 'role' | 'dept' | 'all'
    handle: string                         // what gets inserted (without @)
    label: string                          // shown in the popover
    sublabel?: string
}

const mentionOptions = computed<MentionOption[]>(() => {
    const q = mentionQuery.value.toLowerCase()
    const opts: MentionOption[] = []

    // Always offer @all if it matches.
    if ('all'.startsWith(q) || 'channel'.startsWith(q)) {
        opts.push({ kind: 'all', handle: 'all', label: '@all', sublabel: 'Everyone in this channel' })
    }

    // Channel members ; match first / last / full name.
    for (const m of channelMembers.value) {
        if (! q || m.name.toLowerCase().includes(q) || m.handle.includes(q)) {
            opts.push({
                kind: 'user',
                handle: m.handle,
                label: m.name,
                sublabel: m.department,
            })
        }
    }

    // Roles (job titles).
    for (const jt of jobTitleOptions.value) {
        if (! q || jt.code.includes(q) || jt.label.toLowerCase().includes(q)) {
            opts.push({
                kind: 'role',
                handle: jt.code,
                label: '@' + jt.code,
                sublabel: jt.label,
            })
        }
    }

    // Departments. Slug uses underscores ; mention syntax uses hyphens.
    for (const d of departmentOptions.value) {
        const slugAsMention = d.slug.replace(/_/g, '-')
        if (! q || slugAsMention.includes(q) || d.label.toLowerCase().includes(q)) {
            opts.push({
                kind: 'dept',
                handle: slugAsMention,
                label: '@' + slugAsMention,
                sublabel: d.label,
            })
        }
    }

    return opts.slice(0, 8)
})

function chipClass(kind: 'user' | 'role' | 'dept' | 'all'): string {
    switch (kind) {
        case 'user': return 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200 border border-blue-200 dark:border-blue-700'
        case 'role': return 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-200 border border-indigo-200 dark:border-indigo-700'
        case 'dept': return 'bg-teal-50 dark:bg-teal-900/30 text-teal-800 dark:text-teal-200 border border-teal-200 dark:border-teal-700'
        case 'all':  return 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 border border-amber-200 dark:border-amber-700'
    }
}

/**
 * Parse the textarea contents around the cursor. If we find an unclosed
 * '@<chars>' token, open the typeahead. Otherwise close it.
 */
function checkMentionContext() {
    const el = inputRef.value
    if (! el) { mentionOpen.value = false; return }

    const cursor = el.selectionStart ?? 0
    const before = input.value.slice(0, cursor)
    const match  = before.match(/(^|\s)@([A-Za-z0-9_\.\-]*)$/)
    if (match) {
        mentionStart.value = cursor - match[2].length - 1 // index of '@'
        mentionQuery.value = match[2]
        mentionOpen.value = true
        mentionHighlight.value = 0
    } else {
        mentionOpen.value = false
    }
}

function selectMention(opt: MentionOption) {
    const el = inputRef.value
    if (! el || mentionStart.value < 0) return

    const cursor = el.selectionStart ?? input.value.length
    // Replace the partial '@<typed>' with '@<handle> '
    const beforeAt = input.value.slice(0, mentionStart.value)
    const afterCursor = input.value.slice(cursor)
    input.value = beforeAt + '@' + opt.handle + ' ' + afterCursor
    mentionOpen.value = false

    nextTick(() => {
        // Place cursor right after the inserted ' '.
        const newPos = beforeAt.length + 1 + opt.handle.length + 1
        el.focus()
        el.setSelectionRange(newPos, newPos)
    })
}

function onMentionKey(e: KeyboardEvent) {
    if (! mentionOpen.value || mentionOptions.value.length === 0) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        mentionHighlight.value = (mentionHighlight.value + 1) % mentionOptions.value.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        mentionHighlight.value = (mentionHighlight.value - 1 + mentionOptions.value.length) % mentionOptions.value.length
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault()
        selectMention(mentionOptions.value[mentionHighlight.value])
    } else if (e.key === 'Escape') {
        mentionOpen.value = false
    }
}

/**
 * Render a message_text into a list of segments — plain text + colored
 * chips for any @mention that matches one of the parsed mentions in
 * `m.mentions`. The frontend doesn't need to re-parse the text ; the
 * server already told us which @tokens resolved.
 */
interface Segment { type: 'text' | 'chip'; value: string; chipKind?: 'user' | 'role' | 'dept' | 'all' }
function renderSegments(m: Message): Segment[] {
    const text = m.message_text ?? ''
    if (! text) return []
    const segs: Segment[] = []
    // Walk every @<token> in the text. If the message has a matching
    // mention row, render it as a chip ; otherwise leave plain.
    const re = /@([A-Za-z0-9_\.\-]+)/g
    let last = 0
    let match: RegExpExecArray | null
    while ((match = re.exec(text)) !== null) {
        if (match.index > last) {
            segs.push({ type: 'text', value: text.slice(last, match.index) })
        }
        const token = match[1].toLowerCase()
        const kind = mentionKindForToken(token, m)
        if (kind) {
            segs.push({ type: 'chip', value: '@' + match[1], chipKind: kind })
        } else {
            segs.push({ type: 'text', value: '@' + match[1] })
        }
        last = match.index + match[0].length
    }
    if (last < text.length) segs.push({ type: 'text', value: text.slice(last) })
    return segs
}

function mentionKindForToken(token: string, m: Message): 'user' | 'role' | 'dept' | 'all' | null {
    if (token === 'all' || token === 'channel') {
        return m.mentions.find(x => x.is_at_all) ? 'all' : null
    }
    // Try dept (hyphens).
    if (m.mentions.find(x => x.mentioned_department === token.replace(/-/g, '_'))) return 'dept'
    if (m.mentions.find(x => x.mentioned_role_code === token)) return 'role'
    // User : token has a dot, members lookup compares "first.last".
    if (m.mentions.find(x => {
        if (x.mentioned_user_id == null) return false
        // We can't compare to a name here without a per-user map. Accept
        // any user mention as long as a token-with-dot is present.
        return token.includes('.')
    })) return 'user'
    return null
}

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

/**
 * Look up the count + my_reaction state for a given reaction code on a
 * specific message. Returns undefined when no one has reacted with that
 * code yet ; the always-visible palette uses this to render a faded
 * resting state vs. an active highlighted state.
 */
function reactionFor(m: Message, code: string): Reaction | undefined {
    return m.reactions.find(r => r.reaction === code)
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
    // Save the OUTGOING channel's composer state before swapping.
    captureCurrentDraft()

    activeChannel.value = c
    showSearch.value = false
    showPinPanel.value = false
    showSettings.value = false
    mentionOpen.value = false

    // Restore the INCOMING channel's draft (if any).
    restoreDraftFor(c.id)

    await loadMessages(c.id)
    await axios.post(`/chat/channels/${c.id}/read`)
    // Load lookups in parallel so the @mention typeahead is ready as soon
    // as the user starts typing. These are cached after first load.
    await Promise.all([
        loadChannelMembers(c.id),
        loadJobTitles(),
        loadDepartments(),
    ])
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
        // Wipe the persisted draft for this channel since we just sent it.
        if (activeChannel.value) {
            delete drafts.value[activeChannel.value.id]
            persistDrafts()
        }
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
    loadDraftsFromLocal()
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
    // Capture any in-flight draft so a navigation-away doesn't lose it.
    captureCurrentDraft()
})

// Debounced persist on every keystroke. Slack-style : the draft survives
// even if the tab crashes or the user closes the browser.
let draftSaveTimer: ReturnType<typeof setTimeout> | null = null
watch([input, isUrgent, editingId], () => {
    if (! activeChannel.value) return
    if (draftSaveTimer) clearTimeout(draftSaveTimer)
    draftSaveTimer = setTimeout(() => captureCurrentDraft(), 400)
})

// ── Channel-create flows ──────────────────────────────────────────────────────

const newRoleGroup = ref<{
    name: string; description: string;
    job_title_codes: string[];  // checklist
    departments: string[];      // checklist
    site_wide: boolean;
}>({ name: '', description: '', job_title_codes: [], departments: [], site_wide: false })

async function openCreateRoleGroup() {
    await Promise.all([loadJobTitles(), loadDepartments()])
    showCreateRoleGroup.value = true
}

async function createRoleGroup() {
    if (newRoleGroup.value.job_title_codes.length === 0) {
        alert('Pick at least one job title.')
        return
    }
    if (! newRoleGroup.value.site_wide && newRoleGroup.value.departments.length === 0) {
        alert('Pick at least one department, or check the site-wide box.')
        return
    }
    if (! confirm('All future role-holders will see the entire history of this conversation when they are added. Confirm this is appropriate for a clinical group chat.')) return
    try {
        await axios.post('/chat/role-group-channels', {
            name: newRoleGroup.value.name,
            description: newRoleGroup.value.description || null,
            job_title_codes: newRoleGroup.value.job_title_codes,
            departments: newRoleGroup.value.departments,
            site_wide: newRoleGroup.value.site_wide,
        })
        showCreateRoleGroup.value = false
        newRoleGroup.value = { name: '', description: '', job_title_codes: [], departments: [], site_wide: false }
        await loadChannels()
    } catch (e: any) {
        alert(e.response?.data?.message || 'Failed to create channel.')
    }
}

const newGroupDm = ref<{
    name: string;
    selectedMembers: DmUser[];   // selected so we can show + remove chips
    query: string;
}>({ name: '', selectedMembers: [], query: '' })

function openCreateGroupDm() {
    showCreateGroupDm.value = true
    showDmSearch.value = false
    // Reset state in case the modal was opened earlier.
    newGroupDm.value = { name: '', selectedMembers: [], query: '' }
    tenantUserSearch.value = []
}

watch(() => newGroupDm.value.query, async (q) => {
    if (q.trim().length < 2) { tenantUserSearch.value = []; return }
    const r = await axios.get('/chat/users/search', { params: { q } })
    tenantUserSearch.value = r.data.users ?? []
})

function toggleGroupDmMember(u: DmUser) {
    const exists = newGroupDm.value.selectedMembers.find(m => m.id === u.id)
    if (exists) {
        newGroupDm.value.selectedMembers = newGroupDm.value.selectedMembers.filter(m => m.id !== u.id)
    } else {
        newGroupDm.value.selectedMembers.push(u)
    }
}

async function createGroupDm() {
    if (newGroupDm.value.selectedMembers.length < 2) {
        alert('A group DM needs at least 2 other members (3 total including you).')
        return
    }
    try {
        await axios.post('/chat/group-dm-channels', {
            name: newGroupDm.value.name || null,
            member_user_ids: newGroupDm.value.selectedMembers.map(m => m.id),
        })
        showCreateGroupDm.value = false
        newGroupDm.value = { name: '', selectedMembers: [], query: '' }
        tenantUserSearch.value = []
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
                        @click="openCreateRoleGroup"
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
                        @click="openCreateGroupDm"
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
                                    <p v-else class="mt-1 text-sm text-slate-800 dark:text-slate-200 whitespace-pre-wrap">
                                        <template v-for="(seg, idx) in renderSegments(m)" :key="idx">
                                            <span v-if="seg.type === 'text'">{{ seg.value }}</span>
                                            <span
                                                v-else
                                                :class="['inline-block rounded px-1 py-0 mx-0.5 text-xs font-medium', chipClass(seg.chipKind!)]"
                                            >{{ seg.value }}</span>
                                        </template>
                                    </p>

                                    <!-- Reactions : always-visible palette ; the user clicks
                                         any icon to toggle. Active state = solid icon + pill,
                                         resting state = outline icon + neutral. -->
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                        <template v-for="p in REACTION_PALETTE" :key="p.code">
                                            <button
                                                @click="toggleReaction(m.id, p.code)"
                                                :title="p.label + (reactionFor(m, p.code)?.count ? ' (' + reactionFor(m, p.code)!.count + ')' : '')"
                                                :class="[
                                                    'rounded px-1.5 py-0.5 inline-flex items-center gap-1 border transition-colors',
                                                    reactionFor(m, p.code)?.my_reaction
                                                        ? 'bg-indigo-50 dark:bg-indigo-900/30 border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300'
                                                        : reactionFor(m, p.code)?.count
                                                          ? 'bg-slate-50 dark:bg-slate-700/50 border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                                                          : 'border-transparent text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/40',
                                                ]"
                                            >
                                                <component
                                                    :is="reactionFor(m, p.code)?.my_reaction ? p.iconSolid : p.iconOutline"
                                                    class="w-3.5 h-3.5"
                                                />
                                                <span v-if="reactionFor(m, p.code)?.count" class="font-mono text-[10px]">{{ reactionFor(m, p.code)!.count }}</span>
                                            </button>
                                        </template>
                                        <button @click="openReceipts(m.id)" class="ml-2 text-[10px] text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 hover:underline inline-flex items-center gap-0.5">
                                            <EyeOutline class="w-3 h-3" />
                                            {{ m.read_count }}/{{ m.total_members }}
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
                    <div class="border-t border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 relative">
                        <!-- @mention typeahead popover -->
                        <div
                            v-if="mentionOpen && mentionOptions.length > 0"
                            class="absolute bottom-full left-3 right-3 mb-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl overflow-hidden max-h-64 overflow-y-auto z-20"
                        >
                            <div class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700/50">
                                Mention {{ mentionQuery ? '"' + mentionQuery + '"' : '' }}
                            </div>
                            <ul>
                                <li v-for="(opt, i) in mentionOptions" :key="opt.kind + ':' + opt.handle">
                                    <button
                                        @mousedown.prevent="selectMention(opt)"
                                        @mouseenter="mentionHighlight = i"
                                        :class="[
                                            'w-full text-left px-3 py-1.5 text-sm flex items-center gap-2',
                                            i === mentionHighlight ? 'bg-indigo-50 dark:bg-indigo-900/30' : 'hover:bg-slate-50 dark:hover:bg-slate-700/40',
                                        ]"
                                    >
                                        <span :class="['rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', chipClass(opt.kind)]">{{ opt.kind }}</span>
                                        <span class="font-medium text-slate-800 dark:text-slate-200">{{ opt.label }}</span>
                                        <span v-if="opt.sublabel" class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ opt.sublabel }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div v-if="editingId" class="text-xs text-amber-700 dark:text-amber-300 mb-1">
                            Editing message (5-minute window) <button @click="cancelEdit" class="text-slate-500 hover:underline">cancel</button>
                        </div>
                        <div class="flex items-end gap-2">
                            <textarea
                                ref="inputRef"
                                v-model="input"
                                @input="checkMentionContext"
                                @keyup.left="checkMentionContext"
                                @keyup.right="checkMentionContext"
                                @click="checkMentionContext"
                                @keydown="onMentionKey"
                                @keydown.enter.prevent="! $event.shiftKey && ! mentionOpen && send()"
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
        <div v-if="showCreateRoleGroup" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="showCreateRoleGroup = false">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-2xl w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Create specialized chat</h3>
                <p class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/40 rounded p-3 border border-amber-200 dark:border-amber-700">
                    <strong>Heads up :</strong> all future role-holders will see the entire history of this conversation when they're auto-added. Confirm this is appropriate for a clinical group chat.
                </p>

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Channel name</label>
                    <input v-model="newRoleGroup.name" placeholder="e.g. RN Huddle" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Description (optional)</label>
                    <input v-model="newRoleGroup.description" placeholder="What's this channel for?" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2" />
                </div>

                <!-- Job titles checklist -->
                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Job titles to include</label>
                    <div v-if="jobTitleOptions.length === 0" class="text-xs text-slate-500 dark:text-slate-400 italic">
                        No job titles defined yet. Add them in Executive &raquo; Job Titles first.
                    </div>
                    <div v-else class="grid grid-cols-2 gap-1.5 max-h-44 overflow-y-auto rounded border border-slate-200 dark:border-slate-700 p-2">
                        <label v-for="jt in jobTitleOptions" :key="jt.code" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/40 rounded px-2 py-1 cursor-pointer">
                            <input type="checkbox" :value="jt.code" v-model="newRoleGroup.job_title_codes" class="rounded" />
                            <span>{{ jt.label }} <span class="text-xs text-slate-400 font-mono">{{ jt.code }}</span></span>
                        </label>
                    </div>
                </div>

                <!-- Scope -->
                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Scope</label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 mb-2">
                        <input type="checkbox" v-model="newRoleGroup.site_wide" class="rounded" />
                        <span>Site-wide (every department)</span>
                    </label>
                    <div v-if="! newRoleGroup.site_wide" class="grid grid-cols-2 gap-1.5 max-h-40 overflow-y-auto rounded border border-slate-200 dark:border-slate-700 p-2">
                        <label v-for="d in departmentOptions" :key="d.slug" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/40 rounded px-2 py-1 cursor-pointer">
                            <input type="checkbox" :value="d.slug" v-model="newRoleGroup.departments" class="rounded" />
                            <span>{{ d.label }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showCreateRoleGroup = false" class="px-3 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200">Cancel</button>
                    <button
                        @click="createRoleGroup"
                        :disabled="! newRoleGroup.name || newRoleGroup.job_title_codes.length === 0 || (! newRoleGroup.site_wide && newRoleGroup.departments.length === 0)"
                        class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white disabled:opacity-50 disabled:cursor-not-allowed"
                    >Create channel</button>
                </div>
            </div>
        </div>

        <!-- Create group DM -->
        <div v-if="showCreateGroupDm" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="showCreateGroupDm = false">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">New group DM</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Add 2 or more people from your organisation. You'll be added as a member automatically.</p>

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Group name (optional)</label>
                    <input v-model="newGroupDm.name" placeholder="e.g. Project Sunrise" class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2" />
                </div>

                <!-- Selected member chips -->
                <div v-if="newGroupDm.selectedMembers.length > 0" class="flex flex-wrap gap-1.5">
                    <span v-for="m in newGroupDm.selectedMembers" :key="m.id" class="inline-flex items-center gap-1 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-200 text-xs px-2 py-0.5">
                        {{ m.name }}
                        <button @click="toggleGroupDmMember(m)" class="text-indigo-600 dark:text-indigo-300 hover:text-indigo-900 dark:hover:text-indigo-100">&times;</button>
                    </span>
                </div>

                <!-- User search -->
                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Add members</label>
                    <input v-model="newGroupDm.query" placeholder="Type a name (min 2 chars)..." class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2" />
                    <ul v-if="tenantUserSearch.length > 0" class="mt-2 max-h-40 overflow-y-auto rounded border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="u in tenantUserSearch" :key="u.id">
                            <button
                                @click="toggleGroupDmMember(u)"
                                class="w-full flex items-center justify-between px-3 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-700/40 text-left"
                            >
                                <span class="text-slate-800 dark:text-slate-200">{{ u.name }} <span class="text-xs text-slate-500 ml-1">{{ u.department }}</span></span>
                                <span v-if="newGroupDm.selectedMembers.find(m => m.id === u.id)" class="text-xs text-emerald-600 dark:text-emerald-400">✓ Added</span>
                                <span v-else class="text-xs text-indigo-600 dark:text-indigo-400">+ Add</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showCreateGroupDm = false" class="px-3 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200">Cancel</button>
                    <button
                        @click="createGroupDm"
                        :disabled="newGroupDm.selectedMembers.length < 2"
                        class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white disabled:opacity-50 disabled:cursor-not-allowed"
                    >Create group DM</button>
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
                        <li v-for="r in receipts.reactions" :key="r.user_id + r.reaction" class="flex justify-between items-center">
                            <span class="inline-flex items-center gap-1">
                                {{ r.name }}
                                <component :is="paletteFor(r.reaction)?.iconSolid" class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
                            </span>
                            <span class="text-slate-400">{{ formatTime(r.reacted_at) }}</span>
                        </li>
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
