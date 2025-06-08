// theme/assets/js/app.js

document.addEventListener('DOMContentLoaded', function () {
  const api_url = `${window.location.origin}/wp-json/custom-api/v1/`;
  const DB_NAME = 'ffc-app-db';
  const DB_VERSION = 1;
  const STORE_NAME = 'api-responses';
  let db;

  // Debug logging function
  function debugLog(message, data = null) {
    console.log(`[PWA Debug] ${message}`, data || '');
  }

  // Initialize IndexedDB
  function initIndexedDB() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      
      request.onerror = event => {
        console.error('IndexedDB error:', event.target.error);
        reject('Could not open IndexedDB');
      };
      
      request.onsuccess = event => {
        db = event.target.result;
        console.log('IndexedDB initialized successfully');
        resolve(db);
      };
      
      request.onupgradeneeded = event => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          const store = db.createObjectStore(STORE_NAME, { keyPath: 'id' });
          store.createIndex('endpoint', 'endpoint', { unique: false });
          store.createIndex('timestamp', 'timestamp', { unique: false });
          console.log('Object store created');
        }
      };
    });
  }

  // Save API response to IndexedDB
  function saveToIndexedDB(endpoint, params, data) {
    return new Promise((resolve, reject) => {
      if (!db) {
        reject('Database not initialized');
        return;
      }
      const transaction = db.transaction([STORE_NAME], 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      const id = `${endpoint}:${JSON.stringify(params)}`;
      const item = { id, endpoint, params, data, timestamp: Date.now() };
      const request = store.put(item);
      request.onsuccess = () => {
        console.log('Data saved to IndexedDB:', id);
        resolve();
      };
      request.onerror = event => {
        console.error('Error saving to IndexedDB:', event.target.error);
        reject(event.target.error);
      };
    });
  }

  // Get API response from IndexedDB
  function getFromIndexedDB(endpoint, params) {
    return new Promise((resolve, reject) => {
      if (!db) {
        reject('Database not initialized');
        return;
      }
      const transaction = db.transaction([STORE_NAME], 'readonly');
      const store = transaction.objectStore(STORE_NAME);
      const id = `${endpoint}:${JSON.stringify(params)}`;
      const request = store.get(id);
      request.onsuccess = event => {
        const result = event.target.result;
        if (result) {
          console.log('Data retrieved from IndexedDB:', result);
          resolve(result.data);
        } else {
          reject('No matching data found in IndexedDB');
        }
      };
      request.onerror = event => {
        console.error('Error retrieving from IndexedDB:', event.target.error);
        reject(event.target.error);
      };
    });
  }

  // Fetch API with offline support and 1-hour cache strategy
function fetchWithOfflineSupport(endpoint, method = 'GET', params = {}) {
  const CACHE_DURATION = 60 * 60 * 1000; // 1 hour
  
  // Helper to check if server is actually reachable
  function isServerReachable() {
    if (!navigator.onLine) return Promise.resolve(false);
    
    // Try a lightweight ping to your API
    return fetch(`${api_url}`, { method: 'HEAD', mode: 'no-cors' })
      .then(() => true)
      .catch(() => false);
  }
  
  // First, always try to get from IndexedDB
  return getFromIndexedDB(endpoint, params)
    .then(cachedData => {
      if (cachedData) {
        // We have cached data, check if we should try to update it
        return new Promise((resolve, reject) => {
          if (!db) {
            reject('Database not initialized');
            return;
          }
          
          const transaction = db.transaction([STORE_NAME], 'readonly');
          const store = transaction.objectStore(STORE_NAME);
          const id = `${endpoint}:${JSON.stringify(params)}`;
          const request = store.get(id);
          
          request.onsuccess = async event => {
            const result = event.target.result;
            if (result) {
              const age = Date.now() - result.timestamp;
              
              // Always return cached data immediately for better UX
              debugLog(`Returning cached data (age: ${Math.round(age / 1000 / 60)} minutes)`);
              resolve({ ...result.data, _fromCache: true });
              
              // If cache is fresh or we're offline, we're done
              if (!navigator.onLine || age < CACHE_DURATION) {
                return;
              }
              
              // Cache is stale and we appear to be online
              // Try to update in background without blocking
              isServerReachable().then(reachable => {
                if (reachable) {
                  debugLog('Server reachable, updating cache in background');
                  
                  const fetchOptions = {
                    method,
                    headers: { 'Content-Type': 'application/json' }
                  };
                  if (method === 'POST') {
                    fetchOptions.body = JSON.stringify(params);
                  }
                  
                  fetch(`${api_url}${endpoint}`, fetchOptions)
                    .then(response => response.json())
                    .then(data => {
                      saveToIndexedDB(endpoint, params, data).catch(err => {
                        console.warn('Could not update cache:', err);
                      });
                    })
                    .catch(err => {
                      debugLog('Background update failed:', err);
                    });
                }
              });
            }
          };
          
          request.onerror = event => {
            reject(event.target.error);
          };
        });
      } else {
        // No cached data
        throw new Error('No cached data available');
      }
    })
    .catch(async err => {
      // No cached data, check if we can fetch
      debugLog('No cached data, checking server availability');
      
      const serverReachable = await isServerReachable();
      
      if (!serverReachable) {
        // Server not reachable, return offline response
        if (endpoint === 'app-get-menu') {
          // Return a basic menu structure for offline use
          return {
            success: true,
            menu_html: `
              <div class="app-menu offline-menu">
                <ul>
                  <li><a href="/">Home (Offline Mode)</a></li>
                  <li><a href="#" onclick="location.reload()">Retry Connection</a></li>
                </ul>
              </div>
            `,
            _offline: true
          };
        }
        
        throw new Error('Server not reachable and no cached data available');
      }
      
      // Server is reachable, fetch normally
      const fetchOptions = {
        method,
        headers: { 'Content-Type': 'application/json' }
      };
      if (method === 'POST') {
        fetchOptions.body = JSON.stringify(params);
      }
      
      return fetch(`${api_url}${endpoint}`, fetchOptions)
        .then(response => {
          if (!response.ok) {
            throw new Error('API request failed');
          }
          return response.json();
        })
        .then(data => {
          // Save to IndexedDB
          saveToIndexedDB(endpoint, params, data).catch(err => {
            console.warn('Could not save to IndexedDB:', err);
          });
          return data;
        });
    });
}

  // Insert menu HTML into the DOM
  function insertMenuIntoDOM(menuHTML) {
    const navElement = document.querySelector('body > nav');
    if (navElement) {
      navElement.innerHTML = menuHTML;
      debugLog('Menu HTML inserted into DOM');
      window.navData = { auth_id: '' }; // fake navData for nav.js
      setupNavigation();
      return true;
    }
    return false;
  }

  // Setup navigation functionality directly
  function setupNavigation() {
    debugLog('Setting up navigation');
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    if (!hamburger || !closeNavBtn || !nav || !backdrop) {
      console.error('Navigation elements not found', {
        hamburger: !!hamburger,
        closeNavBtn: !!closeNavBtn,
        nav: !!nav,
        backdrop: !!backdrop
      });
      setTimeout(setupNavigation, 500);
      return;
    }
    debugLog('Found all navigation elements');

    // Handle Submenus
    const menuItems = nav.querySelectorAll('.menu-item-has-children');
    menuItems.forEach(menuItem => {
      const submenu = menuItem.querySelector('.sub-menu');
      if (!submenu) return;
      const expandIcon = document.createElement('div');
      expandIcon.classList.add('expand-icon');
      expandIcon.textContent = '+';
      menuItem.insertBefore(expandIcon, menuItem.firstChild);
      const link = menuItem.querySelector('a');
      if (link) {
        link.addEventListener('click', e => {
          e.preventDefault();
          debugLog('Submenu link clicked');
          expandMenu(submenu, expandIcon);
        });
      }
    });

    // Initial display states
    hamburger.style.display = 'block';
    closeNavBtn.style.display = 'none';
    nav.classList.remove('slide-out');
    backdrop.classList.remove('fade');
    nav.style.display = 'none';
    backdrop.style.display = 'none';

    // Event handlers
    hamburger.addEventListener('click', e => {
      debugLog('Hamburger clicked');
      e.preventDefault();
      e.stopPropagation();
      openWebsiteMenu();
    });
    hamburger.addEventListener('touchstart', e => {
      debugLog('Hamburger touched');
      e.preventDefault();
      e.stopPropagation();
      openWebsiteMenu();
    }, { passive: false });

    closeNavBtn.addEventListener('click', e => {
      debugLog('Close button clicked');
      e.preventDefault();
      e.stopPropagation();
      closeWebsiteMenu();
    });
    closeNavBtn.addEventListener('touchstart', e => {
      debugLog('Close button touched');
      e.preventDefault();
      e.stopPropagation();
      closeWebsiteMenu();
    }, { passive: false });

    backdrop.addEventListener('click', e => {
      debugLog('Backdrop clicked');
      e.preventDefault();
      e.stopPropagation();
      closeWebsiteMenu();
    });
    backdrop.addEventListener('touchstart', e => {
      debugLog('Backdrop touched');
      e.preventDefault();
      e.stopPropagation();
      closeWebsiteMenu();
    }, { passive: false });

    // Show/hide based on auth (if needed)
    if (window.navData && window.navData.auth_id) {
      const signupBtn = document.querySelector('body > nav ul > li:nth-last-of-type(5)');
      const orderHistoryBtn = document.querySelector('body > nav ul > li:nth-last-of-type(4)');
      const dashboardBtn = document.querySelector('body > nav ul > li:nth-last-of-type(3)');
      const logoutBtn = document.querySelector('body > nav ul > li:nth-last-of-type(2)');
      const loginBtn = document.querySelector('body > nav ul > li:last-of-type');
      if (signupBtn) signupBtn.style.display = 'none';
      if (loginBtn) loginBtn.style.display = 'none';
      if (orderHistoryBtn) orderHistoryBtn.style.display = 'block';
      if (dashboardBtn) dashboardBtn.style.display = 'block';
      if (logoutBtn) logoutBtn.style.display = 'block';
    }
    debugLog('Navigation setup complete');
  }

  function openWebsiteMenu() {
    debugLog('Opening menu');
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    if (!nav || !backdrop || !hamburger || !closeNavBtn) {
      console.error('Elements missing for openWebsiteMenu');
      return;
    }
    nav.style.display = 'grid';
    backdrop.style.display = 'block';
    backdrop.style.pointerEvents = 'auto';
    hamburger.style.display = 'none';
    closeNavBtn.style.display = 'block';
    nav.classList.remove('slide-out');
    backdrop.classList.remove('fade');
    debugLog('Menu opened');
  }

  function closeWebsiteMenu() {
    debugLog('Closing menu');
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    if (!nav || !backdrop || !hamburger || !closeNavBtn) {
      console.error('Elements missing for closeWebsiteMenu');
      return;
    }
    nav.classList.add('slide-out');
    backdrop.classList.add('fade');
    backdrop.style.pointerEvents = 'none';
    hamburger.style.display = 'block';
    closeNavBtn.style.display = 'none';
    setTimeout(() => {
      if (nav.classList.contains('slide-out')) {
        nav.style.display = 'none';
        backdrop.style.display = 'none';
      }
    }, 500);
    debugLog('Menu closed');
  }

  function expandMenu(menuItemEle, expandIcon) {
    if (menuItemEle.style.maxHeight) {
      menuItemEle.style.maxHeight = null;
      expandIcon.textContent = '+';
    } else {
      menuItemEle.style.maxHeight = menuItemEle.scrollHeight + 'px';
      expandIcon.textContent = '-';
    }
  }

  // Load menu function with robust error handling
  function loadMenu() {
    debugLog('Loading menu...');
    return fetchWithOfflineSupport('app-get-menu', 'POST', { message: "Initializing app menu" })
      .then(data => {
        if (data && data.success && data.menu_html) {
          debugLog(`Menu data ${data._fromCache ? "loaded from cache" : "fetched from server"}`);
          const inserted = insertMenuIntoDOM(data.menu_html);
          if (!inserted) throw new Error('Failed to insert menu into DOM');
          document.body.classList.remove('loading-menu');
          if (data._fromCache) debugLog('Using cached menu data');
        } else {
          throw new Error('Invalid menu data received');
        }
      })
      .catch(error => {
        console.error('Failed to load menu:', error);
        document.body.classList.remove('loading-menu');
        document.body.classList.add('menu-load-failed');
        insertFallbackMenu();
      });
  }

  // Fallback menu for when everything fails
  function insertFallbackMenu() {
    const navElement = document.querySelector('body > nav');
    if (navElement) {
      navElement.innerHTML = `
        <div class="app-menu">
          <ul>
            <li><a href="/">Home</a></li>
            <li><a href="#" onclick="alert('Menu data unavailable offline. Please connect to the internet to refresh.')">Refresh Menu</a></li>
          </ul>
        </div>
      `;
      setupNavigation();
    }
  }

  // Register service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker
        .register('/wp-content/themes/firefly-collective/service-worker.js',
          { scope: '/wp-content/themes/firefly-collective/' }
        )
        .then(function(registration) {
          console.log('Service worker registration succeeded:', registration);
          registration.addEventListener('updatefound', () => {
            debugLog('Service worker update found');
          });
        })
        .catch(function(error) {
          console.log('Service worker registration failed:', error);
        });
    });
  }

  // Check for network connection status
  function updateOnlineStatus() {
    const condition = navigator.onLine ? "online" : "offline";
    console.log(`Connection status: ${condition}`);
    if (condition === "offline") {
      document.body.classList.add('is-offline');
    } else {
      document.body.classList.remove('is-offline');
      if (db) {
        loadMenu();
      }
    }
  }

  window.addEventListener('online', updateOnlineStatus);
  window.addEventListener('offline', updateOnlineStatus);
  updateOnlineStatus(); // Initial check

  // Add loading state
  document.body.classList.add('loading-menu');

  // Initialize IndexedDB first
  initIndexedDB()
    .then(() => {
      debugLog('IndexedDB initialized, loading menu');
      return loadMenu();
    })
    .catch(error => {
      console.error('Failed to initialize IndexedDB:', error);
      loadMenu();
    });

  // Preload menu data when online to ensure it's cached
  if (navigator.onLine) {
    setTimeout(() => {
      fetchWithOfflineSupport('app-get-menu', 'POST', { message: "Preloading menu data" })
        .then(() => debugLog('Menu data preloaded'))
        .catch(() => debugLog('Menu preload failed'));
    }, 5000);
  }

  // Safety measure for hamburger menu delegation
  document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'hamburger') {
      debugLog('Hamburger clicked via delegation');
      e.preventDefault();
      e.stopPropagation();
      openWebsiteMenu();
    }
  });
});