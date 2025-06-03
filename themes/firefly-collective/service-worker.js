// theme/service-worker.js

// Use content hashing to detect changes automatically
const CACHE_PREFIX = 'ffc-';
const STATIC_CACHE = `${CACHE_PREFIX}static`;
const ASSETS_CACHE = `${CACHE_PREFIX}assets`;
const DYNAMIC_CACHE = `${CACHE_PREFIX}dynamic`;
const theme_path = '/wp-content/themes/firefly-collective';

// List of assets to cache on install
const CORE_ASSETS = [
  theme_path + '/views/app.html',
  theme_path + '/assets/css/app.css',
  theme_path + '/assets/js/app.js'
];

// Audio assets (cached separately)
const AUDIO_ASSETS = [];

// Image assets (cached separately for efficient updates)
const IMAGE_ASSETS = [
  theme_path + '/images/ffc-logo.webp',
  theme_path + '/images/ffc-logo-192.webp'
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
 * Extract the ETag or Last-Modified header to use as a version identifier
 * This helps detect changed files without requiring manual version changes
 */
async function getResourceVersion(url, defaultVersion = Date.now().toString()) {
  try {
    const response = await fetch(url, { method: 'HEAD', cache: 'no-store' });
    if (!response.ok) return defaultVersion;
    
    // Try to get ETag or Last-Modified headers
    const etag = response.headers.get('ETag');
    if (etag) return etag.replace(/"/g, '');
    
    const lastModified = response.headers.get('Last-Modified');
    if (lastModified) return new Date(lastModified).getTime().toString();
    
    // Check for query parameter version (?v=)
    const urlObj = new URL(url, self.location.origin);
    if (urlObj.searchParams.has('v')) {
      return urlObj.searchParams.get('v');
    }
    
    // If cannot determine version, use current timestamp
    return defaultVersion;
  } catch (error) {
    console.log('[SW] Error getting resource version:', error);
    return defaultVersion;
  }
}

/**
 * Get a cache key that includes the resource's version
 * This allows multiple versions of the same resource to coexist in cache
 */
async function getCacheKey(url) {
  const version = await getResourceVersion(url);
  return `${url}?v=${version}`;
}

/**
 * Install event - cache initial assets but don't block activation
 * This allows the service worker to activate quickly
 */
self.addEventListener('install', event => {
  // Skip waiting immediately to take control of clients
  self.skipWaiting();
  
  // Start caching assets but don't block activation
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
            });
          })
        );
      }),
      
      // Cache audio assets
      caches.open(ASSETS_CACHE).then(cache => {
        // Process audio assets in smaller batches
        return Promise.all(
          AUDIO_ASSETS.map(async url => {
            const cacheKey = await getCacheKey(url);
            const request = new Request(url);
            return fetch(request).then(response => {
              if (response.ok) {
                return cache.put(cacheKey, response);
              }
            }).catch(error => {
            });
          })
        );
      }),
      
      // Start caching image assets (but don't wait for completion)
      caches.open(ASSETS_CACHE).then(async cache => {
        // Process images in small batches to avoid overwhelming the browser
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
      })
    ])
  );
});

/**
 * Activate event - clean up old caches and take control immediately
 */
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      // Keep all current caches
      const validCaches = [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE];
      
      // Delete any old caches not in our list
      return Promise.all(
        cacheNames
          .filter(cacheName => cacheName.startsWith(CACHE_PREFIX) && !validCaches.includes(cacheName))
          .map(cacheName => {
            return caches.delete(cacheName);
          })
      );
    })
    .then(() => {
      // Claim all clients immediately so our service worker takes control
      return self.clients.claim();
    })
  );
});

/**
 * Background update function
 * This updates a cached resource without affecting the current user experience
 */
async function updateCacheInBackground(request) {
  try {
    const url = request.url;
    
    // Choose the appropriate cache based on the request URL
    let cacheName = DYNAMIC_CACHE;
    if (CORE_ASSETS.some(asset => url.includes(asset))) {
      cacheName = STATIC_CACHE;
    } else if ([...AUDIO_ASSETS, ...IMAGE_ASSETS, ...FONT_ASSETS].some(asset => url.includes(asset))) {
      cacheName = ASSETS_CACHE;
    }
    
    // Get a versioned cache key
    const cacheKey = await getCacheKey(url);
    
    // Fetch the latest version (bypass cache)
    const fetchOptions = {
      cache: 'no-cache',
      credentials: 'same-origin',
      headers: new Headers({ 'Cache-Control': 'no-cache' })
    };
    
    const response = await fetch(request, fetchOptions);
    if (!response || !response.ok) return null;
    
    // Update the cache with the new version using the versioned key
    const cache = await caches.open(cacheName);
    await cache.put(cacheKey, response.clone());
    
    return response;
  } catch (error) {
    console.log('[SW] Background update failed:', error);
    return null;
  }
}

/**
 * Main fetch handler - responds with cached content while updating in background
 */
self.addEventListener('fetch', event => {
  const request = event.request;
  
  // Skip non-GET requests and cross-origin requests
  if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
    return;
  }
  
  // Handle HTML document requests (like the main page)
  if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
    event.respondWith(
      // First check the cache
      caches.match(request).then(async cachedResponse => {
        // Start a background fetch to update the cache regardless of cache hit
        const fetchPromise = fetch(request).then(networkResponse => {
          if (networkResponse && networkResponse.ok) {
            // Clone the response before using it
            const responseToCache = networkResponse.clone();
            
            // Update the cache with the new version
            caches.open(STATIC_CACHE).then(async cache => {
              const cacheKey = await getCacheKey(request.url);
              cache.put(cacheKey, responseToCache);
            });
          }
          return networkResponse;
        }).catch(error => {
          console.log('[SW] Navigation fetch failed:', error);
          return null;
        });
        
        // If we have a cached version, use it right away
        if (cachedResponse) {
          // Trigger a background update but don't wait for it
          updateCacheInBackground(request);
          return cachedResponse;
        }
        
        // If no cached version, wait for the network response
        const networkResponse = await fetchPromise;
        return networkResponse || new Response('App is offline. Please try again when you have a network connection.', {
          status: 503,
          headers: { 'Content-Type': 'text/html' }
        });
      })
    );
    return;
  }
  
  // For all other assets (JS, CSS, images, etc.)
  event.respondWith(
    // Try to find a cached response that matches the request
    caches.match(request).then(async cachedResponse => {
      // Start updating the resource in the background
      // regardless of whether we have it cached
      updateCacheInBackground(request);
      
      // If we have a cached version, use it immediately
      if (cachedResponse) {
        return cachedResponse;
      }
      
      // If not cached, try the network
      try {
        const networkResponse = await fetch(request);
        
        // Clone the response before using it
        const responseToCache = networkResponse.clone();
        
        // Save the network response to cache for next time
        const cacheName = AUDIO_ASSETS.some(asset => request.url.includes(asset)) || 
                          IMAGE_ASSETS.some(asset => request.url.includes(asset)) || 
                          FONT_ASSETS.some(asset => request.url.includes(asset)) 
                        ? ASSETS_CACHE : DYNAMIC_CACHE;
        
        caches.open(cacheName).then(async cache => {
          const cacheKey = await getCacheKey(request.url);
          cache.put(cacheKey, responseToCache);
        });
        
        return networkResponse;
      } catch (error) {
        console.log('[SW] Fetch failed:', error);
        
        // If both cache and network fail, return a simple error response
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
  
  // Handle skipWaiting message (legacy support)
  if (message && message.action === 'skipWaiting') {
    self.skipWaiting();
  }
  
  // Handle refresh cache message (optional, for force refresh)
  if (message && message.action === 'refreshCache') {
    event.waitUntil(
      Promise.all(ALL_ASSETS.map(url => updateCacheInBackground(new Request(url))))
        .then(() => {
          // Complete
        })
    );
  }
});