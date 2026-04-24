// ─── NostosEMR Vue — Application Entry Point ─────────────────────────────────
// Bootstraps the Inertia + Vue 3 SPA.
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

// Phase O2 — register the portal service worker after initial render so we
// don't delay first paint. Scoped to /portal/ so internal staff routes aren't
// affected by the cache-first shell. Bump `CACHE` in public/sw.js per release
// until an asset-hash invalidation strategy lands.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/portal/' }).catch(() => {
            // Silent — PWA is a progressive enhancement; nothing fails if it's absent.
        })
    })
}
