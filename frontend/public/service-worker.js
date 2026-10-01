/*
 * Muninn service worker — intentionally minimal (CLAUDE.md: installability, not offline-first).
 *
 * It caches nothing and does not intercept requests, so every page and API call always goes to
 * the network. Its only job is to exist so browsers treat Muninn as an installable app.
 */
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (activateEvent) {
    activateEvent.waitUntil(self.clients.claim());
});
