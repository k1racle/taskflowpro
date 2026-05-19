// TaskFlow Pro Service Worker
const CACHE_NAME = 'taskflow-pro-v11';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/manifest.json',
  '/favicon.png',
  '/app.png',
  '/assets/js/app_combined.js',
  '/assets/js/api.js',
  '/assets/js/utils.js'
];

function isAppShellRequest(request) {
  const url = new URL(request.url);
  return url.pathname === '/' ||
         url.pathname.endsWith('.html') ||
         url.pathname.endsWith('.js') ||
         url.pathname.endsWith('.css');
}

// Install
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// Activate
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

// Fetch
self.addEventListener('fetch', (e) => {
  const { request } = e;

  if (request.method !== 'GET') return;

  // API: всегда из сети (никакого кэша API-ответов)
  if (request.url.includes('/api/')) {
    // Ensure API requests never use any caches.
    e.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  // App shell: network-first, fallback to cache
  if (isAppShellRequest(request)) {
    e.respondWith((async () => {
      try {
        const networkResponse = await fetch(request);
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, networkResponse.clone());
        return networkResponse;
      } catch (_) {
        const cached = await caches.match(request);
        if (cached) return cached;
        throw _;
      }
    })());
    return;
  }

  // Остальное: cache-first
  e.respondWith((async () => {
    const cached = await caches.match(request);
    if (cached) return cached;
    const networkResponse = await fetch(request);
    const cache = await caches.open(CACHE_NAME);
    cache.put(request, networkResponse.clone());
    return networkResponse;
  })());
});
