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
