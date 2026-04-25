// ─── sw.js — minimal cache-first service worker for Nostos Portal PWA ────────
// Caches the login shell and static assets so the portal shell loads offline.
// Falls back to network for all API requests.
//
// RELEASE NOTE: bump CACHE on every deploy until we automate asset-hash-based
// invalidation. Old caches are purged in the activate handler.
const CACHE = 'nostos-portal-20260425162428';
const SHELL = ['/portal/login', '/portal/overview', '/manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL).catch(() => {})));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  // Only handle same-origin GETs for the portal shell.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith('/portal/') && !url.pathname.startsWith('/build/')) return;

  e.respondWith(
    caches.match(req).then((cached) => cached || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => caches.match('/portal/login')))
  );
});
