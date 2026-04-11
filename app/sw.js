/* ============================================================
   SKYGUARD — Service Worker (PWA)
   Estratégia: Cache First para assets estáticos
               Network First para API e dados dinâmicos
   ============================================================ */

const CACHE_VERSION = 'skyguard-v2';
const CACHE_STATIC  = `${CACHE_VERSION}-static`;
const CACHE_DYNAMIC = `${CACHE_VERSION}-dynamic`;

// Assets que sempre ficam em cache (relativos ao escopo do SW)
const STATIC_ASSETS = [
  './',
  './login.html',
  './pages/home.html',
  './pages/dashboard.html',
  './pages/devices.html',
  './pages/device-detail.html',
  './pages/contact.html',
  './pages/profile.html',
  './pages/users.html',
  './pages/devices-admin.html',
  './css/style.css',
  './js/nav.js',
  './logo.jpg',
  './manifest.json',
  '/icons/icon-192x192.png',
  '/icons/icon-512x512.png',
];

// ── INSTALL ───────────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_STATIC).then(cache => {
      return Promise.all(
        STATIC_ASSETS.map(url =>
          cache.add(new Request(url, { cache: 'reload' }))
            .catch(err => console.warn('[SW] Não cacheado:', url, err))
        )
      );
    }).then(() => self.skipWaiting())
  );
});

// ── ACTIVATE ─────────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_STATIC && k !== CACHE_DYNAMIC)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH ─────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // API → sempre rede, nunca cache
  if (url.pathname.includes('/api/')) {
    event.respondWith(fetch(event.request).catch(() =>
      new Response(JSON.stringify({ error: 'Sem conexão' }),
        { headers: { 'Content-Type': 'application/json' } })
    ));
    return;
  }

  // Apenas GET vai para cache
  if (event.request.method !== 'GET') {
    event.respondWith(fetch(event.request));
    return;
  }

  // Assets estáticos → Cache First
  const reqPath = url.pathname.replace(self.registration.scope.replace(location.origin, ''), './');
  const isStatic = STATIC_ASSETS.some(a => url.pathname.endsWith(a.replace('./', '/')));

  if (isStatic) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) {
          // Atualiza o cache em background
          fetch(event.request).then(resp => {
            if (resp && resp.status === 200)
              caches.open(CACHE_STATIC).then(c => c.put(event.request, resp));
          }).catch(() => {});
          return cached;
        }
        return fetch(event.request).then(resp => {
          if (resp && resp.status === 200) {
            const clone = resp.clone();
            caches.open(CACHE_STATIC).then(c => c.put(event.request, clone));
          }
          return resp;
        });
      })
    );
    return;
  }

  // Demais → Network First, fallback cache
  event.respondWith(
    fetch(event.request)
      .then(resp => {
        if (resp && resp.status === 200) {
          const clone = resp.clone();
          caches.open(CACHE_DYNAMIC).then(c => c.put(event.request, clone));
        }
        return resp;
      })
      .catch(() => caches.match(event.request).then(cached => {
        if (cached) return cached;
        if (event.request.headers.get('accept')?.includes('text/html'))
          return caches.match('./login.html');
      }))
  );
});

// ── MENSAGENS ─────────────────────────────────────────────────
self.addEventListener('message', event => {
  if (event.data?.action === 'skipWaiting') self.skipWaiting();
});
