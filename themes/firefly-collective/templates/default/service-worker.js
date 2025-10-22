// theme/service-worker.js

// DEV MODE TOGGLE - Set to true to always fetch fresh content
const devMode = true;

// Centralized logging function
function swLog(message, ...args) {
  return; // Temporarily disabled
  if (devMode || !self.location.hostname.includes('localhost')) {
    console.log(`[SW] ${message}`, ...args);
  }
}

// Cache configuration
const CACHE_PREFIX    =   'ffc-';
const STATIC_CACHE    =   `${CACHE_PREFIX}static`;
const ASSETS_CACHE    =   `${CACHE_PREFIX}assets`;
const DYNAMIC_CACHE   =   `${CACHE_PREFIX}dynamic`;
const API_CACHE       =   `${CACHE_PREFIX}api`;
const METADATA_CACHE  =   `${CACHE_PREFIX}metadata`;
const themePath       =   '/wp-content/themes/firefly-collective';
const activeTemplate  =   'default';
const plugin_path     =   `/wp-content/themes/firefly-collective/templates/${activeTemplate}`;
const templatePath    =   `${themePath}/templates/${activeTemplate}`;

// Cache duration (1 hour in milliseconds)
const CACHE_DURATION = 60 * 60 * 1000; // 1 hour
let templateAssetsList = null;

// API endpoints to cache separately
const API_ROUTES = [
  '/wp-json/custom-api/v1/app-init'
];

// Core theme assets (these are now in the shell, so we only cache for offline access)
const CORE_THEME_ASSETS = [
  templatePath    +   '/views/app.html',
  templatePath    +   '/assets/css/gutenberg.css',
  templatePath    +   '/assets/css/app.css',
  templatePath    +   '/assets/css/auth.css',
  templatePath    +   '/assets/css/dashboard.css',
  templatePath    +   '/assets/js/auth.js',
  templatePath    +   '/assets/js/dashboard.js',
  templatePath    +   '/assets/js/app.js',
  templatePath    +   '/manifest.json',

  templatePath +   '/assets/css/nav.css',
  templatePath +   '/assets/css/calendar.css',
  templatePath +   '/assets/js/signup.js',
  
  plugin_path  +   '/assets/css/orders.css',
  plugin_path  +   '/assets/js/orders.js',
];

// Core template assets (files with _core_ prefix)
function getCoreTemplateAssets() {
  return [
    `${templatePath}/assets/css/_core_custom-properties.css`,
    `${templatePath}/assets/css/_core_main.css`,
    `${templatePath}/assets/css/_core_animations.css`,
    `${templatePath}/assets/css/_core_nav.css`,
    `${templatePath}/assets/css/_core_default.css`,
    `${templatePath}/assets/js/_core_main.js`,
    `${templatePath}/assets/js/_core_nav.js`,
  ];
}

// Template-specific assets (generated dynamically)
function getTemplateAssets() {
  // If we have a dynamic asset list from the server, use it
  if (templateAssetsList && templateAssetsList.css && templateAssetsList.js) {
    return [...templateAssetsList.css, ...templateAssetsList.js];
  }
  
  // Fallback to common template asset patterns (excluding _core_ files)
  const commonAssets = [
    `${templatePath}/assets/js/blog.js`,
    `${templatePath}/assets/js/contact.js`,
    `${templatePath}/assets/js/signup.js`,
    `${templatePath}/assets/js/calendar.js`,
    `${templatePath}/assets/js/request-an-appointment.js`,
    `${templatePath}/assets/css/default.css`,
  ];
  
  return commonAssets;
}

// Audio assets (cached separately)
const AUDIO_ASSETS = [];

// Image assets (cached separately for efficient updates)
const IMAGE_ASSETS = [
  templatePath + '/images/ffc-logo.webp',
  templatePath + '/images/ffc-logo-192.webp',
  templatePath + '/images/logo.webp',
  templatePath + '/images/hamburger.webp',
  templatePath + '/images/close-nav.webp',
  templatePath + '/images/loading.gif'
];

// Font assets
const FONT_ASSETS = [];

// External assets
const EXTERNAL_ASSETS = [
  'https://js.stripe.com/v3/',
  'https://unpkg.com/vue@3/dist/vue.global.js',
  'https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg'
];

// Combine all core assets
function getAllAssets() {
  return [
    ...CORE_THEME_ASSETS,
    ...getTemplateAssets(templateName),
    ...AUDIO_ASSETS,
    ...IMAGE_ASSETS,
    ...FONT_ASSETS
  ];
}

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
  swLog(`Cached: ${cacheKey}`);
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
      swLog(`Found in ${cacheName}: ${cacheKey}`);
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
    swLog('POST request cached:', cacheKey);
    return true;
  } catch (error) {
    swLog('Failed to cache POST request:', error);
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
        swLog('Found valid cached POST response');
        return cachedResponse;
      }
    }
    return null;
  } catch (error) {
    swLog('Failed to retrieve cached POST response:', error);
    return null;
  }
}

/**
 * Cache assets for a specific template
 */
async function cacheTemplateAssets(templateName) {
  swLog(`Caching assets for template: ${templateName}`);
  const cache = await caches.open(STATIC_CACHE);
  
  // Cache core template assets
  const coreTemplateAssets = getCoreTemplateAssets(templateName);
  for (const asset of coreTemplateAssets) {
    try {
      const response = await fetch(asset);
      if (response.ok) {
        await cache.put(asset, response);
        swLog(`Cached core template asset: ${asset}`);
      }
    } catch (error) {
      swLog(`Failed to cache core template asset ${asset}:`, error);
    }
  }
  
  // Cache regular template assets
  const templateAssets = getTemplateAssets(templateName);
  for (const asset of templateAssets) {
    try {
      const response = await fetch(asset);
      if (response.ok) {
        await cache.put(asset, response);
        swLog(`Cached template asset: ${asset}`);
      }
    } catch (error) {
      swLog(`Failed to cache template asset ${asset}:`, error);
    }
  }
}

/**
 * Install event - cache initial assets
 */
self.addEventListener('install', event => {
  swLog(`Installing service worker, devMode: ${devMode}`);
  
  // Always skip waiting to activate immediately
  self.skipWaiting();
  
  // Critical files that should be cached even in dev mode for offline support
  const criticalFiles = [
    ...CORE_THEME_ASSETS,
    ...getCoreTemplateAssets('default'), // Cache core template assets for default template
    ...getTemplateAssets('default'), // Always cache default template
    ...IMAGE_ASSETS,
    ...EXTERNAL_ASSETS
  ];
  
  event.waitUntil(
    (async () => {
      // Always cache critical files for offline support
      const cache = await caches.open(STATIC_CACHE);
      for (const file of criticalFiles) {
        try {
          const response = await fetch(file);
          if (response.ok) {
            await cache.put(file, response);
            swLog(`Cached critical file: ${file}`);
          }
        } catch (error) {
          swLog(`Failed to cache critical file ${file}:`, error);
        }
      }
      
      // Skip the rest if in dev mode
      if (devMode) {
        swLog('Dev mode enabled - skipping full asset cache');
        return;
      }
      
      // Production mode - cache all assets for all available templates
      swLog('Production mode - caching all template assets');
      
      // Cache common templates
      const commonTemplates = ['default', 'modern', 'classic']; // Add your template names here
      for (const template of commonTemplates) {
        await cacheTemplateAssets(template);
      }
      
    })().then(() => {
      swLog('Installation complete');
    })
  );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', event => {
  swLog('Activating service worker');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      const validCaches = [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE, API_CACHE, METADATA_CACHE];
      return Promise.all(
        cacheNames
          .filter(name => name.startsWith(CACHE_PREFIX) && !validCaches.includes(name))
          .map(name => {
            swLog('Deleting old cache:', name);
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

  // Never cache these payment/subscription endpoints
  const NEVER_CACHE_PATHS = [
    '/wp-json/custom-api/v1/create-payment-intent',
    '/wp-json/custom-api/v1/place-order',
    '/wp-json/custom-api/v1/update-payment-status',
    '/wp-json/custom-api/v1/stripe-webhook',
    '/wp-json/custom-api/v1/refund-payment',
    '/wp-json/custom-api/v1/change-subscription-plan',
    '/wp-json/custom-api/v1/complete-plan-change',
    '/wp-json/custom-api/v1/cancel-subscription',
    '/wp-json/custom-api/v1/update-payment-method',
    '/wp-json/custom-api/v1/check-subscription-status',
    '/wp-json/custom-api/v1/get-orders',
    '/wp-json/custom-api/v1/get-subscriptions'
  ];

  if (NEVER_CACHE_PATHS.some(p => url.pathname.startsWith(p))) {
    // Network-only, don't touch Cache Storage
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(() =>
        new Response(JSON.stringify({ success: false, message: 'Offline' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  // Skip non-GET/POST
  if (!['GET', 'POST'].includes(request.method)) {
    return;
  }

  // Handle external resources
  const isExternal = !request.url.startsWith(self.location.origin);
  if (isExternal) {
    // Cache important external resources
    const allowedExternals = [
      'js.stripe.com',
      'unpkg.com',
      'www.gstatic.com',
      'cdnjs.cloudflare.com',
      'm.stripe.com' // Add mobile stripe
    ];

    const shouldCache = allowedExternals.some(domain => request.url.includes(domain));
    if (!shouldCache) {
      return; // Let browser handle other external requests
    }

    // Handle external resources with cache-first strategy
    event.respondWith(
      caches.match(request).then(cachedResponse => {
        if (cachedResponse) {
          return cachedResponse;
        }
        return fetch(request).then(response => {
          // Cache the external resource
          if (response.ok) {
            const responseToCache = response.clone();
            caches.open(ASSETS_CACHE).then(cache => {
              cache.put(request, responseToCache);
            });
          }
          return response;
        }).catch(() => {
          // Return a fake response for Stripe to prevent errors
          if (request.url.includes('stripe')) {
            return new Response('', { status: 200 });
          }
          return new Response('External resource unavailable offline', { status: 503 });
        });
      })
    );
    return;
  }

  // Development mode - check offline status first
  if (devMode) {
    swLog('Dev mode fetch:', request.url);
    event.respondWith(
      (async () => {
        if (!navigator.onLine) {
          swLog('Offline - going straight to cache');
          if (request.url.includes('/wp-json/')) {
            return new Response(JSON.stringify({
              success: false,
              message: 'Offline - check your connection',
              _offline: true
            }), {
              status: 200,
              headers: { 'Content-Type': 'application/json' }
            });
          }
          if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
            const appShellUrl = themePath + '/views/app.html';
            for (const cacheName of [STATIC_CACHE, DYNAMIC_CACHE]) {
              const cache = await caches.open(cacheName);
              const cachedResponse = await cache.match(appShellUrl);
              if (cachedResponse) {
                swLog('Returning cached app shell (offline)');
                return cachedResponse;
              }
            }
          }
          for (const cacheName of [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE]) {
            const cache = await caches.open(cacheName);
            const cachedResponse = await cache.match(request.url);
            if (cachedResponse) {
              swLog('Serving from cache (offline):', request.url);
              return cachedResponse;
            }
          }
          return new Response('Resource not available offline', {
            status: 503,
            headers: { 'Content-Type': 'text/plain' }
          });
        }
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000);
        try {
          const response = await fetch(request.clone(), { signal: controller.signal });
          clearTimeout(timeoutId);
          return response;
        } catch (error) {
          clearTimeout(timeoutId);
          swLog('Network failed or timed out, checking cache');
          if (request.url.includes('/wp-json/')) {
            return new Response(JSON.stringify({
              success: false,
              message: 'Network timeout',
              _offline: true
            }), {
              status: 200,
              headers: { 'Content-Type': 'application/json' }
            });
          }
          for (const cacheName of [STATIC_CACHE, ASSETS_CACHE, DYNAMIC_CACHE]) {
            const cache = await caches.open(cacheName);
            const cachedResponse = await cache.match(request.url);
            if (cachedResponse) {
              swLog('Serving from cache after timeout:', request.url);
              return cachedResponse;
            }
          }
          return new Response('Resource unavailable', {
            status: 503,
            headers: { 'Content-Type': 'text/plain' }
          });
        }
      })()
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
            swLog('Serving POST from cache');
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
            swLog('Serving API from cache (still fresh)');
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
            swLog('Offline - serving stale API cache');
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
            swLog('Serving HTML from cache (still fresh)');
            return cachedResponse;
          }
          swLog('HTML cache expired, fetching fresh');
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
            swLog('Offline - serving stale HTML cache');
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
          swLog('Serving from cache (still fresh):', request.url);
          return cachedResponse;
        }
        swLog('Cache expired for:', request.url);
      }
      
      // Not in cache or expired - fetch from network
      try {
        swLog('Fetching from network:', request.url);
        const response = await fetch(request);
        
        if (response.ok) {
          // Determine cache name - check if it's a template asset
          let cacheName = DYNAMIC_CACHE;

          if (CORE_THEME_ASSETS.some(asset => request.url.includes(asset))) {
            cacheName = STATIC_CACHE;
          } else if (request.url.includes('/templates/')) {
            // Template-specific asset
            cacheName = STATIC_CACHE;
          } else if ([...AUDIO_ASSETS, ...IMAGE_ASSETS, ...FONT_ASSETS].some(asset => request.url.includes(asset))) {
            cacheName = ASSETS_CACHE;
          }
          
          await cacheWithMetadata(cacheName, request.url, response);
        }
        
        return response;
      } catch (error) {
        swLog('Fetch failed:', error);
        
        // If offline and have stale cache, use it
        if (cachedResponse) {
          swLog('Offline - serving stale cache');
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
    swLog('Dev mode is:', devMode ? 'ENABLED' : 'DISABLED');
  }
  
  if (message && message.action === 'refreshCache') {
    event.waitUntil(
      caches.keys().then(cacheNames => 
        Promise.all(cacheNames.map(name => caches.delete(name)))
      ).then(() => swLog('All caches cleared'))
    );
  }

  // Handle template change
  if (message && message.action === 'setActiveTemplate') {
    activeTemplate = message.template || 'default';
    templateAssetsList = message.assets || null;
    swLog(`Active template set to: ${activeTemplate}`);
    
    // Cache assets for the new template
    event.waitUntil(cacheTemplateAssets(activeTemplate));
  }
});