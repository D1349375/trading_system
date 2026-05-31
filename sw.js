// sw.js - 基本的 Service Worker 以滿足 PWA 條件
const CACHE_NAME = 'quant-terminal-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

// 攔截網路請求：這裡直接放行，確保每次都抓取最新資料 (因為我們是交易系統，資料必須最新)
self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});