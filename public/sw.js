const CACHE_NAME = 'unicorn-antenna-cache-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    // 単純なネットワークファースト戦略（必要に応じて後日オフライン対応拡張）
    event.respondWith(fetch(event.request));
});
