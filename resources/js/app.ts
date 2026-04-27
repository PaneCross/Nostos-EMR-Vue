// ─── NostosEMR Vue - Application Entry Point ─────────────────────────────────
// Bootstraps the Inertia + Vue 3 SPA. This file runs ONCE per page-load on
// the client and configures everything every page depends on:
//
//   1. Vue 3 + Inertia client setup (lazy-loads page components on demand).
//   2. Global axios config (sends cookies, XSRF token, X-Requested-With header).
//   3. Global axios response interceptor (V5/W2) - surfaces 5xx, 403, 409,
//      and network failures as toasts via the 'nostos:toast' CustomEvent
//      that Components/Toaster.vue listens for. Skips 422 (forms render
//      their own per-field errors) and 401 (auth redirect handles it).
//      Reloads the page on 419 (CSRF expired) to refresh the token.
//   4. Reverb / Laravel Echo bootstrap for WebSocket alerts + chat.
//
// Stack acronym primer (one-liner each):
//   Inertia = a thin layer that lets Laravel return Vue components from
//             controllers like a server-side framework, without an SPA API.
//   Vite    = the dev server + production bundler (replaces Webpack).
//   Reverb  = Laravel's WebSocket server (real-time alerts, chat).
//   Echo    = the Laravel-flavored client wrapper around Pusher protocol.
//
// All page components are lazy-loaded via dynamic import for code splitting.
// ─────────────────────────────────────────────────────────────────────────────

import { createApp, h, DefineComponent } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import axios from 'axios'
import '../css/app.css'

// Make axios available globally (used in components for JSON widget requests)
window.axios = axios
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'
// Send session cookies and auto-attach XSRF-TOKEN cookie as X-XSRF-TOKEN header
// on every axios request so Laravel CSRF protection passes for all POST calls.
window.axios.defaults.withCredentials = true
window.axios.defaults.withXSRFToken = true

// ─── Phase U2 + V5 - global axios error surfacer ───────────────────────────
// Audit-9 found multiple empty catch{} blocks that swallow 4xx/5xx silently,
// causing UIs to flip "saved=true" when nothing was persisted. This response
// interceptor logs every failure to the console AND emits a 'nostos:toast'
// CustomEvent picked up by Components/Toaster.vue (mounted in AppShell).
// On 419 (CSRF expiry) we reload to refresh the token.
//
// Toast policy:
//   - 5xx and ERR_NETWORK always toast (server / connectivity failure)
//   - 4xx toasts only for non-validation errors (skip 422 since per-component
//     forms already render per-field validation, and skip 401 since redirect
//     handles auth)
//   - Per-component catch handlers still own their inline UX; toast is a
//     last-resort safety net for empty-catch patterns that haven't been swept.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status
        const url = error?.config?.url ?? '(no url)'
        const method = (error?.config?.method ?? 'get').toUpperCase()
        if (status === 419) {
            console.warn(`[axios] 419 CSRF expired on ${method} ${url}; reloading…`)
            window.location.reload()
            return Promise.reject(error)
        }
        if (status >= 400 || error.code === 'ERR_NETWORK') {
            const statusLabel = status ?? error.code ?? 'network'
            const message = error?.response?.data?.message ?? error?.message ?? 'Request failed'
            console.error(`[axios] ${method} ${url} failed (${statusLabel}):`, message)

            // Toast policy: surface 5xx + network errors, plus 403/409.
            // Skip 422 (per-field forms render their own UX) and 401 (auth redirect).
            const shouldToast =
                error.code === 'ERR_NETWORK'
                || (typeof status === 'number' && (status >= 500 || status === 403 || status === 409))
            if (shouldToast) {
                window.dispatchEvent(new CustomEvent('nostos:toast', {
                    detail: {
                        message: `${message} (${statusLabel})`,
                        severity: 'error',
                        timeout: 6000,
                    },
                }))
            }
        }
        return Promise.reject(error)
    },
)

// Bootstrap Reverb/Echo (WebSockets for real-time alerts + chat)
import './echo'

createInertiaApp({
    title: (title) => (title ? `${title} | NostosEMR` : 'NostosEMR'),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: {
        color: '#4F46E5',
    },
})

// Phase O2 - register the portal service worker after initial render so we
// don't delay first paint. Scoped to /portal/ so internal staff routes aren't
// affected by the cache-first shell. Bump `CACHE` in public/sw.js per release
// until an asset-hash invalidation strategy lands.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/portal/' }).catch(() => {
            // Silent - PWA is a progressive enhancement; nothing fails if it's absent.
        })
    })
}
