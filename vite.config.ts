import { defineConfig, type Plugin } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { readFileSync, writeFileSync, existsSync } from 'fs'
import { resolve } from 'path'

/**
 * Phase P11 — bump the portal service-worker CACHE name on every production
 * build so deployed clients pick up new asset hashes without a manual edit.
 * Uses the build start time as the version stamp.
 */
function bumpServiceWorkerCache(): Plugin {
    return {
        name: 'nostos-bump-sw-cache',
        apply: 'build',
        closeBundle() {
            const swPath = resolve(__dirname, 'public/sw.js')
            if (!existsSync(swPath)) return
            const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14)
            const src = readFileSync(swPath, 'utf-8')
            const next = src.replace(
                /const CACHE = '[^']+';/,
                `const CACHE = 'nostos-portal-${stamp}';`
            )
            if (next !== src) writeFileSync(swPath, next)
        },
    }
}

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.ts',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
        bumpServiceWorkerCache(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
})
