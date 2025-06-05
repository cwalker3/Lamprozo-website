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
  const CACHE_DURATION = 60 * 60 * 1000; // 1 hour in milliseconds
  
  // First, try to get from IndexedDB regardless of online status
  return getFromIndexedDB(endpoint, params)
    .then(cachedData => {
      // Check if we have cached data
      if (cachedData) {
        // Get the timestamp from IndexedDB (need to modify getFromIndexedDB to return the full item)
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
              const age = Date.now() - result.timestamp;
              
              // If offline OR cache is fresh (less than 1 hour old), use it
              if (!navigator.onLine || age < CACHE_DURATION) {
                debugLog(`Using cached data (age: ${Math.round(age / 1000 / 60)} minutes)`);
                resolve({ ...result.data, _fromCache: true });
                return;
              }
              
              // Cache is stale and we're online, fetch fresh data
              debugLog('Cache is stale, fetching fresh data');
              fetchFreshData();
            } else {
              // No cached data, fetch if online
              if (navigator.onLine) {
                fetchFreshData();
              } else {
                reject('You are offline and no cached data is available');
              }
            }
          };
          
          request.onerror = event => {
            reject(event.target.error);
          };
          
          // Helper function to fetch fresh data
          function fetchFreshData() {
            const fetchOptions = {
              method,
              headers: { 'Content-Type': 'application/json' }
            };
            if (method === 'POST') {
              fetchOptions.body = JSON.stringify(params);
            }
            
            fetch(`${api_url}${endpoint}`, fetchOptions)
              .then(response => {
                if (!response.ok) {
                  return response.json()
                    .then(data => Promise.reject(new Error(data.message || 'API request failed')))
                    .catch(() => Promise.reject(new Error('API request failed')));
                }
                return response.json().catch(() => Promise.reject(new Error('Invalid JSON response')));
              })
              .then(data => {
                // Save to IndexedDB with timestamp
                saveToIndexedDB(endpoint, params, data).catch(err => {
                  console.warn('Could not save to IndexedDB:', err);
                });
                resolve(data);
              })
              .catch(error => {
                // Network failed but we have stale cache, use it
                if (result && result.data) {
                  console.warn('Network failed, using stale cache:', error);
                  resolve({ ...result.data, _fromCache: true, _stale: true });
                } else {
                  reject(new Error('Network request failed and no cache available'));
                }
              });
          }
        });
      } else {
        // No cached data at all
        throw new Error('No cached data');
      }
    })
    .catch(err => {
      // No cached data, try network if online
      if (!navigator.onLine) {
        throw new Error('You are offline and no cached data is available');
      }
      
      // Fetch from network
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
            return response.json()
              .then(data => Promise.reject(new Error(data.message || 'API request failed')))
              .catch(() => Promise.reject(new Error('API request failed')));
          }
          return response.json().catch(() => Promise.reject(new Error('Invalid JSON response')));
        })
        .then(data => {
          // Save to IndexedDB with timestamp
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
        <div class="website-menu">
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