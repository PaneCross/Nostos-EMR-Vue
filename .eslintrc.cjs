// ESLint configuration for NostosEMR Vue 3.5
// Enforces Vue 3 best practices, TypeScript safety, and Prettier formatting.
// Run: npm run lint to check and auto-fix all .vue and .ts files.

/* eslint-env node */
require('@rushstack/eslint-patch/modern-module-resolution')

module.exports = {
    root: true,
    extends: [
        'plugin:vue/vue3-recommended',
        '@vue/eslint-config-typescript',
        '@vue/eslint-config-prettier/skip-formatting',
    ],
    parserOptions: {
        ecmaVersion: 'latest',
    },
    rules: {
        // Enforce PascalCase for component names in templates (e.g. <AppShell> not <app-shell>)
        'vue/component-name-in-template-casing': ['error', 'PascalCase'],

        // Warn on unused variables — helps catch stale imports during migration
        'vue/no-unused-vars': 'warn',

        // Warn on explicit `any` — prefer typed values but don't block the build
        '@typescript-eslint/no-explicit-any': 'warn',

        // Warn on console statements — remove before handoff to Brian
        'no-console': 'warn',

        // Allow single-word component names (e.g. <Login>) — common in Inertia apps
        'vue/multi-word-component-names': 'off',

        // Allow unused function parameters (common in Vue event handlers)
        '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],

        // Enforce self-closing tags for components without slot content
        'vue/html-self-closing': ['error', {
            html: { void: 'always', normal: 'never', component: 'always' },
            svg: 'always',
            math: 'always',
        }],
    },
}
