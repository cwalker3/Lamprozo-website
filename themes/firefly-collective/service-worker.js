// theme/service-worker.js

// DEV MODE TOGGLE - Set to true to always fetch fresh content
const devMode = true;

// Cache configuration
const CACHE_PREFIX    = 'ffc-';
const STATIC_CACHE    = `${CACHE_PREFIX}static`;
const ASSETS_CACHE    = `${CACHE_PREFIX}assets`;
const DYNAMIC_CACHE   = `${CACHE_PREFIX}dynamic`;
const API_CACHE       = `${CACHE_PREFIX}api`;
const METADATA_CACHE  = `${CACHE_PREFIX}metadata`;
const theme_path      = '/wp-content/themes/firefly-collective';

// Cache duration (1 hour in milliseconds)
const CACHE_DURATION = 60 * 60 * 1000; // 1 hour

// API endpoints to cache separately
const API_ROUTES = [
  '/wp-json/custom-api/v1/app-init'
];

// List of assets to cache on install
const CORE_ASSETS = [
  theme_path + '/views/app.html',
  theme_path + '/assets/css/main.css',
  theme_path + '/assets/css/app.css',
  theme_path + '/assets/css/dashboard.css',
  theme_path + '/assets/css/animations.css',
  theme_path + '/assets/css/nav.css',
  theme_path + '/assets/js/app.js',
  theme_path + '/assets/js/nav.js',
  theme_path + '/assets/js/main.js',
  theme_path + '/assets/js/dashboard.js',
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
 * Store cache metadata (timestamp) 
 */
async function setCacheMetadata(url, timestamp) {
  const cache = await caches.open(METADATA_CACHE);
  const metadata = new Response(JSON.stringify({ timestamp }), {
    headers: { 'Content-Type': 'application/json' }
  });
  await cache.put(new Request(`${url}:metadata`), metadata);
}

/**
 * Get cache metadata (timestamp)
 */
async function getCacheMetadata(url) {
  const cache = await caches.open(METADATA_CACHE);
  const response = await cache.match(new Request(`${url}:metadata`));
  if (!response) return null;
  try {
    const data = await response.json();
    return data.timestamp;
  } catch {
    return null;
  }
}

/**
 * Check if cached response is expired
 */
async function isCacheExpired(url) {
  if (devMode) return true; // Always expired in dev mode
  
  const timestamp = await getCacheMetadata(url);
  if (!timestamp) return true;
  
  const age = Date.now() - timestamp;
  return age > CACHE_DURATION;
}

/**
 * Get cache key - simple in production, timestamped in dev
 */
function getCacheKey(url) {
  // Remove any existing query params for consistent cache keys
  const cleanUrl = url.split('?')[0];
  
  // In dev mode, add timestamp to bypass cache
  if (devMode) {
    return `${cleanUrl}?_dev=${Date.now()}`;
  }
  
  // In production, use clean URL for stable cache keys
  return cleanUrl;
}

/**
 * Store a response in cache with metadata
 */
async function cacheWithMetadata(cacheName, url, response) {
  const cache = await caches.open(cacheName);
  const cacheKey = getCacheKey(url);
  await cache.put(cacheKey, response.clone());
  await setCacheMetadata(url, Date.now());
  console.log(`[SW] Cached: ${cacheKey}`);
}

/**
 * Get from cache
 */
async function getFromCache(url) {
  const cacheKey = getCacheKey(url);
  
  // Try all caches
  for (const cacheName of [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE, API_CACHE]) {
    const cache = await caches.open(cacheName);
    const response = await cache.match(cacheKey);
    if (response) {
      console.log(`[SW] Found in ${cacheName}: ${cacheKey}`);
      return response;
    }
  }
  
  return null;
}

/**
 * Store a POST request and its response in the cache
 */
async function cachePostRequest(request, response) {
  if (devMode) return false; // No caching in dev mode
  
  try {
    const body = await request.clone().json().catch(() => ({}));
    const url = request.url;
    const cacheKey = `${url}:${JSON.stringify(body)}`;
    const cache = await caches.open(API_CACHE);
    await cache.put(new Request(cacheKey), response.clone());
    await setCacheMetadata(cacheKey, Date.now());
    console.log('[SW] POST request cached:', cacheKey);
    return true;
  } catch (error) {
    console.log('[SW] Failed to cache POST request:', error);
    return false;
  }
}

/**
 * Try to find a cached response for a POST request
 */
async function getCachedPostResponse(request) {
  if (devMode) return null; // Never use cache in dev mode
  
  try {
    const body = await request.clone().json().catch(() => ({}));
    const url = request.url;
    const cacheKey = `${url}:${JSON.stringify(body)}`;
    
    const cache = await caches.open(API_CACHE);
    const cachedResponse = await cache.match(new Request(cacheKey));
    if (cachedResponse) {
      const expired = await isCacheExpired(cacheKey);
      if (!expired) {
        console.log('[SW] Found valid cached POST response');
        return cachedResponse;
      }
    }
    return null;
  } catch (error) {
    console.log('[SW] Failed to retrieve cached POST response:', error);
    return null;
  }
}

/**
 * Install event - cache initial assets
 */
self.addEventListener('install', event => {
  console.log(`[SW] Installing service worker, devMode: ${devMode}`);
  
  // Always skip waiting to activate immediately
  self.skipWaiting();
  
  // Skip caching in dev mode
  if (devMode) {
    console.log('[SW] Dev mode enabled - skipping initial cache');
    return;
  }
  
  console.log('[SW] Production mode - caching assets');
  
  event.waitUntil(
    Promise.all([
      // Cache core assets
      caches.open(STATIC_CACHE).then(async cache => {
        console.log('[SW] Caching core assets...');
        return Promise.all(
          CORE_ASSETS.map(async url => {
            try {
              const response = await fetch(url);
              if (response.ok) {
                await cacheWithMetadata(STATIC_CACHE, url, response);
              }
            } catch (error) {
              console.log(`[SW] Failed to cache ${url}:`, error);
            }
          })
        );
      }),
      // Cache other assets
      caches.open(ASSETS_CACHE).then(async cache => {
        console.log('[SW] Caching other assets...');
        const allOtherAssets = [...AUDIO_ASSETS, ...IMAGE_ASSETS, ...FONT_ASSETS];
        for (const url of allOtherAssets) {
          try {
            const response = await fetch(url);
            if (response.ok) {
              await cacheWithMetadata(ASSETS_CACHE, url, response);
            }
          } catch (error) {
            console.log(`[SW] Failed to cache ${url}:`, error);
          }
        }
      }),
      // Create other caches
      caches.open(DYNAMIC_CACHE),
      caches.open(API_CACHE),
      caches.open(METADATA_CACHE)
    ]).then(() => {
      console.log('[SW] Installation complete');
    })
  );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', event => {
  console.log('[SW] Activating service worker');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      const validCaches = [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE, API_CACHE, METADATA_CACHE];
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
 * Fetch event handler
 */
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip non-GET/POST and cross-origin
  if (!['GET', 'POST'].includes(request.method) || !request.url.startsWith(self.location.origin)) {
    return;
  }

  // In dev mode, always fetch from network
  if (devMode) {
    console.log('[SW] Dev mode fetch:', request.url);
    event.respondWith(
      fetch(request.clone()).catch(() => {
        return new Response('Dev mode: Network request failed', {
          status: 503,
          headers: { 'Content-Type': 'text/plain' }
        });
      })
    );
    return;
  }

  // Production mode - cache first strategy

  // Handle API requests
  const isApiRequest = API_ROUTES.some(route => request.url.includes(route));
  if (isApiRequest) {
    if (request.method === 'POST') {
      event.respondWith(
        (async () => {
          // Check cache first
          const cachedResponse = await getCachedPostResponse(request);
          if (cachedResponse) {
            console.log('[SW] Serving POST from cache');
            return cachedResponse;
          }
          
          // Not in cache or expired, fetch from network
          if (!self.navigator.onLine) {
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
            await cachePostRequest(request.clone(), response.clone());
            return response;
          } catch {
            return new Response(JSON.stringify({
              success: false,
              message: 'Network error',
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

    // GET API requests
    event.respondWith(
      (async () => {
        // Check if cached and not expired
        const cachedResponse = await getFromCache(request.url);
        if (cachedResponse) {
          const expired = await isCacheExpired(request.url);
          if (!expired) {
            console.log('[SW] Serving API from cache (still fresh)');
            return cachedResponse;
          }
        }
        
        // Expired or not cached, fetch from network
        try {
          const response = await fetch(request);
          if (response.ok) {
            await cacheWithMetadata(API_CACHE, request.url, response);
          }
          return response;
        } catch {
          // Offline - return stale cache if available
          if (cachedResponse) {
            console.log('[SW] Offline - serving stale API cache');
            return cachedResponse;
          }
          return new Response(JSON.stringify({
            success: false,
            message: 'You are offline.',
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

  // Handle navigation requests (HTML pages)
  if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
    event.respondWith(
      (async () => {
        // Check cache first
        const cachedResponse = await getFromCache(request.url);
        if (cachedResponse) {
          const expired = await isCacheExpired(request.url);
          if (!expired) {
            console.log('[SW] Serving HTML from cache (still fresh)');
            return cachedResponse;
          }
          console.log('[SW] HTML cache expired, fetching fresh');
        }
        
        // Not cached or expired, fetch from network
        try {
          const response = await fetch(request);
          if (response.ok) {
            await cacheWithMetadata(STATIC_CACHE, request.url, response);
          }
          return response;
        } catch {
          // Offline - return stale cache if available
          if (cachedResponse) {
            console.log('[SW] Offline - serving stale HTML cache');
            return cachedResponse;
          }
          return new Response('App is offline. Please try again when you have a network connection.', {
            status: 503,
            headers: { 'Content-Type': 'text/html' }
          });
        }
      })()
    );
    return;
  }

  // All other assets (JS, CSS, images, etc.)
  event.respondWith(
    (async () => {
      // Always check cache first in production
      const cachedResponse = await getFromCache(request.url);
      
      if (cachedResponse) {
        const expired = await isCacheExpired(request.url);
        if (!expired) {
          console.log('[SW] Serving from cache (still fresh):', request.url);
          return cachedResponse;
        }
        console.log('[SW] Cache expired for:', request.url);
      }
      
      // Not in cache or expired - fetch from network
      try {
        console.log('[SW] Fetching from network:', request.url);
        const response = await fetch(request);
        
        if (response.ok) {
          // Determine cache name
          let cacheName = DYNAMIC_CACHE;
          if (CORE_ASSETS.some(asset => request.url.includes(asset))) {
            cacheName = STATIC_CACHE;
          } else if ([...AUDIO_ASSETS, ...IMAGE_ASSETS, ...FONT_ASSETS].some(asset => request.url.includes(asset))) {
            cacheName = ASSETS_CACHE;
          }
          
          await cacheWithMetadata(cacheName, request.url, response);
        }
        
        return response;
      } catch (error) {
        console.log('[SW] Fetch failed:', error);
        
        // If offline and have stale cache, use it
        if (cachedResponse) {
          console.log('[SW] Offline - serving stale cache');
          return cachedResponse;
        }
        
        return new Response('Resource currently unavailable offline', {
          status: 408,
          headers: { 'Content-Type': 'text/plain' }
        });
      }
    })()
  );
});

/**
 * Listen for messages from the main thread
 */
self.addEventListener('message', event => {
  const message = event.data;
  
  if (message && message.action === 'skipWaiting') {
    self.skipWaiting();
  }
  
  if (message && message.action === 'checkDevMode') {
    console.log('[SW] Dev mode is:', devMode ? 'ENABLED' : 'DISABLED');
  }
  
  if (message && message.action === 'refreshCache') {
    event.waitUntil(
      caches.keys().then(cacheNames => 
        Promise.all(cacheNames.map(name => caches.delete(name)))
      ).then(() => console.log('[SW] All caches cleared'))
    );
  }
});