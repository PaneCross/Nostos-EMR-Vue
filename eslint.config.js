// ESLint flat config for NostosEMR Vue 3.5
// Uses ESLint 10 flat config format (eslint.config.js).
// Enforces Vue 3 best practices, TypeScript safety, and Prettier formatting.
// Run: npm run lint      — check and auto-fix all .vue and .ts files
// Run: npm run lint:check — check only, no changes written

import pluginVue from 'eslint-plugin-vue'
import vueTsEslintConfig from '@vue/eslint-config-typescript'
import prettierConfig from '@vue/eslint-config-prettier'

export default [
    // Apply Vue 3 recommended rules to all .vue files
    ...pluginVue.configs['flat/recommended'],

    // Apply TypeScript rules
    ...vueTsEslintConfig(),

    // Apply Prettier formatting rules last (overrides style conflicts)
    prettierConfig,

    {
        rules: {
            // Enforce PascalCase for component names in templates (<AppShell> not <app-shell>)
            'vue/component-name-in-template-casing': ['error', 'PascalCase'],

            // Warn on unused variables — helps catch stale imports during migration
            'vue/no-unused-vars': 'warn',

            // Warn on explicit `any` — prefer typed values but don't block the build
            '@typescript-eslint/no-explicit-any': 'warn',

            // Warn on console statements — remove before handoff to Brian
            'no-console': 'warn',

            // Allow single-word component names (common in Inertia apps like <Login>)
            'vue/multi-word-component-names': 'off',

            // Warn on unused variables, ignore underscore-prefixed params (_event, etc.)
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],

            // Enforce self-closing tags for components with no slot content
            'vue/html-self-closing': ['error', {
                html: { void: 'always', normal: 'never', component: 'always' },
                svg: 'always',
                math: 'always',
            }],

            // Allow <script setup> without explicit component name
            'vue/component-definition-name-casing': 'off',

            // Allow v-html for server-generated content (Laravel paginator labels,
            // sanitized API responses). Never use v-html on user-supplied input.
            'vue/no-v-html': 'off',
        },
    },

    {
        // Ignore built output, vendor code, and config files
        ignores: [
            'public/**',
            'vendor/**',
            'node_modules/**',
            'bootstrap/ssr/**',
        ],
    },
]
