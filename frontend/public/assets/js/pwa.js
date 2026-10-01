/*
 * Registers the minimal service worker that makes Muninn installable.
 * It does no offline caching (offline editing is out of scope for V1).
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var siteRootMeta = document.querySelector('meta[name="muninn-site-root"]');
    var siteRoot = siteRootMeta ? siteRootMeta.getAttribute('content') : './';

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(siteRoot + 'service-worker.js', { scope: siteRoot }).catch(function () {
            // Installability is a convenience; the app works without it.
        });
    });
})();
