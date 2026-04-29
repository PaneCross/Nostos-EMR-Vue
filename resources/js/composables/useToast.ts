// ─── useToast composable ─────────────────────────────────────────────────────
// Audit-4 B3 : centralizes the toast-event dispatch so callers don't have to
// hand-roll `window.dispatchEvent(new CustomEvent('nostos:toast', ...))` every
// time. Backed by Components/Toaster.vue which is mounted in AppShell.
//
// Usage:
//   const toast = useToast()
//   toast.error('Save failed')
//   toast.success('Saved.')
//   toast.confirm('Delete this?').then(ok => { if (ok) ... })
// ─────────────────────────────────────────────────────────────────────────────

export function useToast() {
    function push(message: string, severity: 'info' | 'warning' | 'error', timeout?: number) {
        window.dispatchEvent(new CustomEvent('nostos:toast', {
            detail: { message, severity, timeout },
        }))
    }

    return {
        success: (msg: string) => push(msg, 'info', 3500),
        info:    (msg: string) => push(msg, 'info'),
        warning: (msg: string) => push(msg, 'warning'),
        error:   (msg: string) => push(msg, 'error'),

        // Confirm replacement : returns a promise that resolves true/false.
        // Uses native confirm() under the hood for now (modal-style confirm
        // would need a global ConfirmModal component). Keeping the callsite
        // ergonomic so a future swap is one-place.
        confirm: (message: string) => Promise.resolve(window.confirm(message)),
    }
}
