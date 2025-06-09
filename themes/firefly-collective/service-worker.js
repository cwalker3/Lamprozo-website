// theme/service-worker.js

// Use content hashing to detect changes automatically
const CACHE_PREFIX    = 'ffc-';
const STATIC_CACHE    = `${CACHE_PREFIX}static`;
const ASSETS_CACHE    = `${CACHE_PREFIX}assets`;
const DYNAMIC_CACHE   = `${CACHE_PREFIX}dynamic`;
const API_CACHE       = `${CACHE_PREFIX}api`;
const theme_path      = '/wp-content/themes/firefly-collective';

// API endpoints to cache separately
const API_ROUTES = [
  '/wp-json/custom-api/v1/app-init'
];

// List of assets to cache on install
const CORE_ASSETS = [
  theme_path + '/views/app.html',
  theme_path + '/assets/css/main.css',
  theme_path + '/assets/css/app.css',
  theme_path + '/assets/css/animations.css',
  theme_path + '/assets/css/nav.css',
  theme_path + '/assets/js/app.js',
  theme_path + '/assets/js/nav.js',
  theme_path + '/assets/js/main.js',
  theme_path + '/assets/js/manifest.json',
];

// Audio assets (cached separately)
const AUDIO_ASSETS = [];

// Image assets (cached separately for efficient updates)
const IMAGE_ASSETS = [
  theme_path + '/images/ffc-logo.webp',
  theme_path + '/images/ffc-logo-192.webp',
  theme_path + '/images/logo.webp',
  theme_path + '/images/hamburger.webp',
  theme_path + '/images/close-nav.webp'
];

// Font assets
const FONT_ASSETS = [];

// Combine all assets
const ALL_ASSETS = [
  ...CORE_ASSETS,
  ...AUDIO_ASSETS,
  ...IMAGE_ASSETS,
  ...FONT_ASSETS
];

/**
 * Get a cache key that includes the resource's version
 * We simply use the plain URL, so cache.match works regardless of queries
 */
async function getCacheKey(url) {
  return url;
}

/**
 * Store a POST request and its response in the cache
 * Uses a special key format to handle POST requests with bodies
 */
async function cachePostRequest(request, response) {
  if (!request.clone().bodyUsed) {
    try {
      const body = await request.clone().json().catch(() => ({}));
      const url = request.url;
      const cacheKey = `${url}:${JSON.stringify(body)}`;
      const cache = await caches.open(API_CACHE);
      const modifiedRequest = new Request(cacheKey, {
        method: 'POST',
        headers: request.headers,
        body: JSON.stringify(body),
        mode: 'cors'
      });
      await cache.put(modifiedRequest, response.clone());
      console.log('[SW] POST request cached:', cacheKey);
      return true;
    } catch (error) {
      console.log('[SW] Failed to cache POST request:', error);
      return false;
    }
  }
  return false;
}

/**
 * Try to find a cached response for a POST request
 */
async function getCachedPostResponse(request) {
  try {
    const body = await request.clone().json().catch(() => ({}));
    const url = request.url;
    const cacheKey = `${url}:${JSON.stringify(body)}`;
    const cache = await caches.open(API_CACHE);
    const cachedResponse = await cache.match(new Request(cacheKey));
    if (cachedResponse) {
      console.log('[SW] Found cached POST response:', cacheKey);
      return cachedResponse;
    }
    return null;
  } catch (error) {
    console.log('[SW] Failed to retrieve cached POST response:', error);
    return null;
  }
}

/**
 * Install event - cache initial assets but don't block activation
 * This allows the service worker to activate quickly
 */
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    Promise.all([
      // Cache core assets
      caches.open(STATIC_CACHE).then(cache => {
        return Promise.all(
          CORE_ASSETS.map(async url => {
            const cacheKey = await getCacheKey(url);
            const request = new Request(url);
            return fetch(request).then(response => {
              if (response.ok) {
                return cache.put(cacheKey, response);
              }
            }).catch(error => {
              console.log(`[SW] Failed to cache ${url}:`, error);
            });
          })
        );
      }),
      // Cache audio assets
      caches.open(ASSETS_CACHE).then(cache => {
        return Promise.all(
          AUDIO_ASSETS.map(async url => {
            const cacheKey = await getCacheKey(url);
            const request = new Request(url);
            return fetch(request).then(response => {
              if (response.ok) {
                return cache.put(cacheKey, response);
              }
            }).catch(error => {
              console.log(`[SW] Failed to cache ${url}:`, error);
            });
          })
        );
      }),
      // Cache image assets (in batches)
      caches.open(ASSETS_CACHE).then(async cache => {
        const batchSize = 5;
        for (let i = 0; i < IMAGE_ASSETS.length; i += batchSize) {
          const batch = IMAGE_ASSETS.slice(i, i + batchSize);
          await Promise.all(
            batch.map(async url => {
              const cacheKey = await getCacheKey(url);
              const request = new Request(url);
              try {
                const response = await fetch(request);
                if (response.ok) {
                  return cache.put(cacheKey, response);
                }
              } catch (error) {
                console.log(`[SW] Failed to cache ${url}:`, error);
              }
            })
          );
        }
      }),
      // Cache font assets
      caches.open(ASSETS_CACHE).then(cache => {
        return Promise.all(
          FONT_ASSETS.map(async url => {
            const cacheKey = await getCacheKey(url);
            const request = new Request(url);
            return fetch(request).then(response => {
              if (response.ok) {
                return cache.put(cacheKey, response);
              }
            }).catch(error => {
              console.log(`[SW] Failed to cache ${url}:`, error);
            });
          })
        );
      }),
      // Create API cache (even if empty initially)
      caches.open(API_CACHE)
    ])
  );
});

/**
 * Activate event - clean up old caches and take control immediately
 */
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      const validCaches = [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE, API_CACHE];
      return Promise.all(
        cacheNames
          .filter(name => name.startsWith(CACHE_PREFIX) && !validCaches.includes(name))
          .map(name => {
            console.log('[SW] Deleting old cache:', name);
            return caches.delete(name);
          })
      );
    }).then(() => self.clients.claim())
  );
});

/**
 * Background update function
 * We will only fetch from network if online, but otherwise skip
 */
async function updateCacheInBackground(request) {
  try {
    const url = request.url;
    let cacheName = DYNAMIC_CACHE;
    if (CORE_ASSETS.some(asset => url.includes(asset))) {
      cacheName = STATIC_CACHE;
    } else if ([...AUDIO_ASSETS, ...IMAGE_ASSETS, ...FONT_ASSETS].some(asset => url.includes(asset))) {
      cacheName = ASSETS_CACHE;
    } else if (API_ROUTES.some(route => url.includes(route))) {
      cacheName = API_CACHE;
    }
    // If offline, skip network fetch entirely
    if (!self.navigator.onLine) return null;
    const cacheKey = await getCacheKey(url);
    const response = await fetch(request).catch(() => null);
    if (!response || !response.ok) return null;
    const cache = await caches.open(cacheName);
    await cache.put(cacheKey, response.clone());
    return response;
  } catch (error) {
    console.log('[SW] Background update failed:', error);
    return null;
  }
}

/**
 * Main fetch handler - never fetch from network if offline;
 * always attempt cache-first, fallback to offline response.
 */
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip non-GET/POST and cross-origin
  if (!['GET', 'POST'].includes(request.method) || !request.url.startsWith(self.location.origin)) {
    return;
  }

  // Handle API requests specially
  const isApiRequest = API_ROUTES.some(route => request.url.includes(route));
  if (isApiRequest) {
    if (request.method === 'POST') {
      event.respondWith(
        (async () => {
          if (!self.navigator.onLine) {
            const cachedResponse = await getCachedPostResponse(request.clone());
            if (cachedResponse) return cachedResponse;
            // Tell app to use IndexedDB
            return new Response(JSON.stringify({
              success: false,
              message: 'Offline - check IndexedDB',
              _offline: true,
              _useIndexedDB: true
            }), {
              status: 200,
              headers: { 'Content-Type': 'application/json' }
            });
          }
          try {
            const response = await fetch(request.clone());
            const responseToCache = response.clone();
            cachePostRequest(request.clone(), responseToCache);
            return response;
          } catch {
            const cachedResponse = await getCachedPostResponse(request.clone());
            if (cachedResponse) return cachedResponse;
            return new Response(JSON.stringify({
              success: false,
              message: 'You are offline. This feature requires an internet connection.',
              _offline: true
            }), {
              status: 200,
              headers: { 'Content-Type': 'application/json' }
            });
          }
        })()
      );
      return;
    }

    // GET API: cache-first, no network if offline
    event.respondWith(
      caches.match(request, { ignoreSearch: true }).then(async cachedResponse => {
        if (cachedResponse) {
          updateCacheInBackground(request);
          return cachedResponse;
        }
        if (!self.navigator.onLine) {
          return new Response(JSON.stringify({
            success: false,
            message: 'You are offline.',
            _offline: true
          }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
          });
        }
        try {
          const response = await fetch(request);
          if (response && response.ok) {
            const responseToCache = response.clone();
            const cache = await caches.open(API_CACHE);
            cache.put(request, responseToCache);
          }
          return response;
        } catch {
          return new Response(JSON.stringify({
            success: false,
            message: 'You are offline.',
            _offline: true
          }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
          });
        }
      })
    );
    return;
  }

  // Handle navigation requests (HTML pages)
  if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
    event.respondWith(
      (async () => {
        // Always serve cached root ("/") when offline
        if (!self.navigator.onLine) {
          const cachedShell = await caches.match('/app.html', { ignoreSearch: true });
          return cachedShell || new Response('App is offline. Please try again when you have a network connection.', {
            status: 503,
            headers: { 'Content-Type': 'text/html' }
          });
        }
        // If online, fetch and update cache
        try {
          const networkResponse = await fetch(request);
          const cache = await caches.open(STATIC_CACHE);
          cache.put('/app.html', networkResponse.clone());
          return networkResponse;
        } catch {
          const cachedShell = await caches.match('/app.html', { ignoreSearch: true });
          return cachedShell || new Response('App is offline. Please try again when you have a network connection.', {
            status: 503,
            headers: { 'Content-Type': 'text/html' }
          });
        }
      })()
    );
    return;
  }

  // All other assets (JS, CSS, images, etc.) – cache-first, never network-fetch if offline
  event.respondWith(
    caches.match(request, { ignoreSearch: true }).then(async cachedResponse => {
      if (cachedResponse) {
        updateCacheInBackground(request);
        return cachedResponse;
      }
      if (!self.navigator.onLine) {
        // If offline and not in cache, respond with a generic offline placeholder
        if (request.destination === 'image') {
          return new Response('', { status: 404 });
        }
        return new Response('Resource currently unavailable offline', {
          status: 408,
          headers: { 'Content-Type': 'text/plain' }
        });
      }
      try {
        const networkResponse = await fetch(request);
        const responseToCache = networkResponse.clone();
        const cacheName = AUDIO_ASSETS.some(asset => request.url.includes(asset)) ||
                          IMAGE_ASSETS.some(asset => request.url.includes(asset)) ||
                          FONT_ASSETS.some(asset => request.url.includes(asset))
                        ? ASSETS_CACHE
                        : DYNAMIC_CACHE;
        const cache = await caches.open(cacheName);
        const cacheKey = await getCacheKey(request.url);
        cache.put(cacheKey, responseToCache);
        return networkResponse;
      } catch {
        if (request.destination === 'image') {
          return new Response('', { status: 404 });
        }
        return new Response('Resource currently unavailable offline', {
          status: 408,
          headers: { 'Content-Type': 'text/plain' }
        });
      }
    })
  );
});

/**
 * Listen for messages from the main thread
 * This is kept minimal since we want updates to happen automatically
 */
self.addEventListener('message', event => {
  const message = event.data;
  if (message && message.action === 'skipWaiting') {
    self.skipWaiting();
  }
  if (message && message.action === 'refreshCache') {
    event.waitUntil(
      Promise.all(ALL_ASSETS.map(url => updateCacheInBackground(new Request(url))))
        .then(() => console.log('[SW] Cache refreshed successfully'))
    );
  }
  if (message && message.action === 'clearApiCache') {
    event.waitUntil(
      caches.open(API_CACHE).then(cache =>
        cache.keys().then(keys => Promise.all(keys.map(key => cache.delete(key))))
      ).then(() => console.log('[SW] API cache cleared successfully'))
    );
  }
});
