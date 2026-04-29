// ─── useModalA11y composable ─────────────────────────────────────────────────
// Audit-4 B2 : wires up the standard accessibility behavior every modal needs:
//   - Escape key closes the modal (window-level keydown listener)
//   - Initial focus moves into the modal so screen readers announce it
//   - Focus restored to the trigger element when the modal closes
//
// Usage:
//   const dialogEl = ref<HTMLElement | null>(null)
//   useModalA11y(dialogEl, () => editing.value = null)
//
// Add `ref="dialogEl"` to the modal's outer element + tabindex="-1".
// ─────────────────────────────────────────────────────────────────────────────

import { type Ref, watch, onBeforeUnmount } from 'vue'

export function useModalA11y(dialogEl: Ref<HTMLElement | null>, onClose: () => void) {
    let priorActive: HTMLElement | null = null

    function onKey(e: KeyboardEvent) {
        if (e.key === 'Escape') onClose()
    }

    watch(dialogEl, (el) => {
        if (el) {
            priorActive = (document.activeElement as HTMLElement) ?? null
            // Move focus into the dialog so SR announces it
            requestAnimationFrame(() => el.focus())
            window.addEventListener('keydown', onKey)
        } else {
            window.removeEventListener('keydown', onKey)
            // Restore focus to whatever was focused before
            if (priorActive && document.contains(priorActive)) priorActive.focus()
            priorActive = null
        }
    })

    onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
}
