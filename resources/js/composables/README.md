# Composables

This folder holds reusable Vue composition functions (composables) for NostosEMR.

## Rule: Check VueUse First

Before writing a new composable, check if [@vueuse/core](https://vueuse.org/) already
has what you need. VueUse is installed and available. Using it means less custom code
to maintain and fewer bugs.

**VueUse functions used in this project:**

| Function | What it does | Used in |
|----------|-------------|---------|
| `useDebounceFn` | Delays a function call until input stops (e.g. search) | Participants Index search bar |
| `useLocalStorage` | Reads/writes localStorage reactively | Theme store |
| `useEventListener` | Adds event listeners that clean up automatically | Chat, modals |
| `useIntersectionObserver` | Detects when an element enters the viewport | Lazy loading, infinite scroll |

## When to Write a Custom Composable

Write a custom composable (in this folder) only when the logic is:
1. **EMR-specific** — e.g. clinical note signing flow, SDR deadline calculation
2. **Not covered by VueUse** — check the docs first
3. **Reused by 2+ components** — if only one component uses it, keep the logic in the component

## File Naming

- One composable per file
- Name matches the function it exports: `useChat.ts` exports `useChat()`
- Keep each file under 100 lines — split into smaller composables if it grows

## Custom Composables in This Project

| File | What it does |
|------|-------------|
| *(populated as migration progresses)* | |
