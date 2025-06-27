// theme/assets/js/app.js

document.addEventListener('DOMContentLoaded', function () {
  const devMode = true;
  const api_url = `${window.location.origin}/wp-json/custom-api/v1/`;
  const DB_NAME = 'ffc-app-db';
  const DB_VERSION = 1;
  const STORE_NAME = 'api-responses';
  let db;

  const loader = document.querySelector('#loader');
  const websiteApp = document.querySelector('#website-app');

  // Debug logging function
  function debugLog(message, data = null) {
    return;
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

  // Fetch API with offline support and 1-hour cache strategy (not in dev mode)
  async function fetchWithOfflineSupport(endpoint, method = 'GET', params = {}) {
    const url = `${api_url}${endpoint}`;
    const CACHE_DURATION = 60 * 60 * 1000; // 1 hour

    // ===== 1) DEV MODE: Network-only with no-store, then cache to IndexedDB =====
    if (devMode) {
      debugLog('DevMode: network-only fetch:', url);
      try {
        const options = {
          method,
          headers: { 'Content-Type': 'application/json' },
          cache: 'no-store'                     // bypass HTTP + SW caches
        };
        if (method === 'POST') options.body = JSON.stringify(params);

        const response = await fetch(url, options);  // The actual fetch
        if (!response.ok) {
          throw new Error(`Network error (${response.status})`);
        }
        const data = await response.json();
        setAuthId(data.auth_id);
        setAppData(data);

        // Persist fresh data into IndexedDB for offline use
        await saveToIndexedDB(endpoint, params, data);
        return data;
      } catch (err) {
        console.warn('DevMode network failed, falling back to cache:', err);
        return getFromIndexedDB(endpoint, params);
      }
    }

    // ===== 2) PRODUCTION MODE: Cache-first via IndexedDB =====

    // 2a) Attempt to read from IndexedDB
    try {
      const cachedData = await getFromIndexedDB(endpoint, params);
      debugLog('ProdMode: returning cached data immediately:', cachedData);
      return cachedData;                     // Return if cache hit
    } catch (_) {
      // No cached data—fall through to network
    }

    // 2b) Network fetch, then cache result
    try {
      debugLog('ProdMode: network fetch:', url);
      const options = {
        method,
        headers: { 'Content-Type': 'application/json' }
      };
      if (method === 'POST') options.body = JSON.stringify(params);

      const response = await fetch(url, options); // The actual fetch
      if (!response.ok) {
        throw new Error(`API error (${response.status})`);
      }
      const data = await response.json();
      setAuthId(data.auth_id);
      setAppData(data);

      // Save fresh data into IndexedDB for next time
      saveToIndexedDB(endpoint, params, data).catch(e =>
        console.warn('Could not save to IndexedDB:', e)
      );
      return data;
    } catch (err) {
      console.error('ProdMode network failed, falling back to cache:', err);
      return getFromIndexedDB(endpoint, params); // final fallback
    }
  }

  function setAuthId(id) {
    window.navData = { auth_id: id };
    window.auth_id = id;
  }

  function setAppData(data) {
    window.api_url  = data.api_url;
    window.nonce    = data.nonce;
    window.http_host = data.http_host;
    window.theme_path = data.theme_path;
    window.app_page_title = data.app_page_title;
    window.app_page_html = data.app_page_html;
  }

  async function getView(view) {
    loader.style.display = 'block';
    try {
      const url = `${window.api_url}app-get-view`;
      const options = {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },

      };
      options.body = JSON.stringify({ view });

      const response = await fetch(url, options);
      if (!response.ok) {
        throw new Error(`Network error (${response.status})`);
      }

      const dataResponse = await response.json();
      if (dataResponse.success) {
        loader.style.display = 'none';
        switch (view) {
          case 'dashboard':
            window.theme_path  = dataResponse.theme_path;
            window.features    = dataResponse.features;
            window.stripeKey   = dataResponse.stripeKey;
          break;

          case 'order-history':
            window.apiUrl      = dataResponse.apiUrl;
            window.data        = dataResponse.data;
          break;
        }
        // Clear appRoot before inserting new content
        appRoot.innerHTML = '';
        appRoot.innerHTML = dataResponse.response_html;
      }
    } catch (err) {
      loader.style.display = 'none';
      console.warn('the network failed:', err);
    }
  }

  // Insert menu HTML into the DOM
  function insertMenuIntoDOM(menuHTML) {
    const navElement = document.querySelector('body > nav');
    if (navElement) {
      navElement.innerHTML = menuHTML;
      debugLog('Menu HTML inserted into DOM');
      
      const navAnchors = document.querySelectorAll('body > nav a');
      navAnchors.forEach(anchor => {  // Use forEach to avoid closure issues
        const anchorText = anchor.innerText;
        const anchorSlug = anchorText.replace(/\s/g, '-').toLowerCase();
        anchor.parentElement.innerHTML = `<div class="app-nav" id="${anchorSlug}">${anchorText}</div>`;
      });
      
      // Setup click handlers after all nav items are created
      const appNavItems = document.querySelectorAll('.app-nav');
      appNavItems.forEach(navItem => {
        const navSlug = navItem.id;
        navItem.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          console.log(`Nav item clicked: ${navSlug}`);
          closeWebsiteMenu(true);  // Use force parameter
          loadContent(navSlug);
        });
      });
      
      setupNavigation();
      return true;
    }
    return false;
  }

  async function loadContent(navSlug) {
    switch (navSlug) {
      case 'back-to-website':
        window.location = `https://${window.http_host}`;
        break;

      case 'log-in':
        appRoot.innerHTML = '';
        loadAppTitleAndAppHTML();
        loadLoginForm();
        scrollToTop();
        break;

      case 'log-out':
        loader.style.display = 'block';
        fetch(`${window.api_url}app-logout/?auth_id=${window.auth_id}`, {
          headers: {
              'Content-Type': 'application/json'
          }
        }).then(response => response.json())
        .then(data => {
          if (data.logout) {
            window.auth_id = null;
            window.navData = null;
            updateNavVisibility();
            appRoot.innerHTML = '';
            loadAppTitleAndAppHTML();
            loadLoginForm();
            loader.style.display = 'none';
          }
        })
        .catch(error => {
          console.error('Error logging out:', error);
          loader.style.display = 'none';
        });
        scrollToTop();
        break;

      case 'dashboard':
        window.resetDashboard();
        await getView('dashboard');
        if (document.getElementById('features-container')) {
            window.initializeDashboard();
            scrollToTop();
        }
        break;

      case 'order-history':
        await getView('order-history');
        initOrdersApp();
        scrollToTop();
        break;
    }
  }

  // Add menu state management
  let menuState = {
    isAnimating: false,
    isOpen: false
  };

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

    // Single event handler for hamburger (use click only)
    hamburger.addEventListener('click', e => {
      debugLog('Hamburger clicked');
      e.preventDefault();
      e.stopPropagation();
      if (!menuState.isAnimating && !menuState.isOpen) {
        openWebsiteMenu();
      }
    });

    // Single event handler for close button
    closeNavBtn.addEventListener('click', e => {
      debugLog('Close button clicked');
      e.preventDefault();
      e.stopPropagation();
      if (!menuState.isAnimating && menuState.isOpen) {
        closeWebsiteMenu();
      }
    });

    // Single event handler for backdrop
    backdrop.addEventListener('click', e => {
      debugLog('Backdrop clicked');
      e.preventDefault();
      e.stopPropagation();
      if (!menuState.isAnimating && menuState.isOpen) {
        closeWebsiteMenu();
      }
    });

    // Show/hide based on auth (if needed)
    updateNavVisibility();
    debugLog('Navigation setup complete');
  }

  function openWebsiteMenu() {
    if (menuState.isAnimating || menuState.isOpen) return;
    
    debugLog('Opening menu');
    menuState.isAnimating = true;
    menuState.isOpen = true;
    
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    
    if (!nav || !backdrop || !hamburger || !closeNavBtn) {
      console.error('Elements missing for openWebsiteMenu');
      menuState.isAnimating = false;
      menuState.isOpen = false;
      return;
    }
    
    nav.style.display = 'grid';
    backdrop.style.display = 'block';
    hamburger.style.display = 'none';
    nav.classList.remove('slide-out');
    backdrop.classList.remove('fade');
    closeNavBtn.style.display = 'block';
    
    // Allow animation to complete before accepting new inputs
    setTimeout(() => {
      menuState.isAnimating = false;
      debugLog('Menu open animation complete');
    }, 600);
    
    debugLog('Menu opened');
  }

  function closeWebsiteMenu(force = false) {
    if (!force && (menuState.isAnimating || !menuState.isOpen)) return;
    
    debugLog('Closing menu');
    menuState.isAnimating = true;
    menuState.isOpen = false;
    
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    
    if (!nav || !backdrop || !hamburger || !closeNavBtn) {
      console.error('Elements missing for closeWebsiteMenu');
      menuState.isAnimating = false;
      menuState.isOpen = true;
      return;
    }
    
    nav.classList.add('slide-out');
    backdrop.classList.add('fade');
    hamburger.style.display = 'block';
    closeNavBtn.style.display = 'none';
    
    setTimeout(() => {
      if (nav.classList.contains('slide-out')) {
        nav.style.display = 'none';
        backdrop.style.display = 'none';
      }
      menuState.isAnimating = false;
      debugLog('Menu close animation complete');
    }, 600);
    
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

  function updateNavVisibility() {
    const signupBtn = document.querySelector('body > nav ul > li:nth-last-of-type(6)');
    const orderHistoryBtn = document.querySelector('body > nav ul > li:nth-last-of-type(5)');
    const dashboardBtn = document.querySelector('body > nav ul > li:nth-last-of-type(4)');
    const backToWebsiteBtn = document.querySelector('body > nav ul > li:nth-last-of-type(3)');
    const logoutBtn = document.querySelector('body > nav ul > li:nth-last-of-type(2)');
    const loginBtn = document.querySelector('body > nav ul > li:last-of-type');
    
    if (window.navData && window.navData.auth_id) {
      // User is logged in
      if (signupBtn) signupBtn.style.display = 'none';
      if (loginBtn) loginBtn.style.display = 'none';
      if (orderHistoryBtn) orderHistoryBtn.style.display = 'block';
      if (dashboardBtn) dashboardBtn.style.display = 'block';
      if (backToWebsiteBtn) backToWebsiteBtn.style.display = 'block';
      if (logoutBtn) logoutBtn.style.display = 'block';
    } else {
      // User is logged out
      if (signupBtn) signupBtn.style.display = 'block';
      if (loginBtn) loginBtn.style.display = 'block';
      if (orderHistoryBtn) orderHistoryBtn.style.display = 'none';
      if (dashboardBtn) dashboardBtn.style.display = 'none';
      if (backToWebsiteBtn) backToWebsiteBtn.style.display = 'none';
      if (logoutBtn) logoutBtn.style.display = 'none';
    }
  }

  // Initialize the app
  function appInit() {
    debugLog('Initializing App...');
    loader.style.display = 'block';
    return fetchWithOfflineSupport('app-init', 'POST')
      .then(async data => {
        if (!data.success) throw new Error('App init failed');
        insertMenuIntoDOM(data.menu_html);

        // Save app page data to IndexedDB
        await saveToIndexedDB('app-page-data', {}, {
          app_page_title: data.app_page_title,
          app_page_html: data.app_page_html
        });

        loadAppTitleAndAppHTML();

        // Dashboard (or log in form if not logged in)
        if (!window.auth_id) loadLoginForm();
        if (window.auth_id) await getView('dashboard'), window.initializeDashboard();
        window.gapiDomain = data.gapiDomain;
        loader.style.display = 'none';
      })
      .catch(error => {
        console.error('Failed to load menu:', error);
        document.body.classList.remove('loading-menu');
        document.body.classList.add('menu-load-failed');
        insertFallbackMenu();
      });
  }

  // App page title and HTML
  function loadAppTitleAndAppHTML() {
    const titleEl = document.createElement('h1');
      titleEl.innerText = window.app_page_title;
      const contentEl = document.createElement('div');
      contentEl.id = 'app-page-html';
      contentEl.innerHTML = window.app_page_html;
      appRoot.appendChild(titleEl);
      appRoot.appendChild(contentEl);
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
          
          // Check for updates immediately
          registration.update();
          
          // Listen for updates
          registration.addEventListener('updatefound', () => {
            console.log('Service worker update found');
            const newWorker = registration.installing;
            
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New service worker ready - but DON'T auto-reload
                console.log('New service worker ready! Refresh to get updates.');
                // Just skip waiting, don't reload
                newWorker.postMessage({ action: 'skipWaiting' });
              }
            });
          });
          
          // DON'T auto-reload on controller change - let user control refreshes
          navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('Service worker updated. New version will be used on next refresh.');
          });
          
          // Check service worker periodically (but don't auto-reload)
          setInterval(() => {
            registration.update();
          }, 60000); // Check every minute
        })
        .catch(function(error) {
          console.log('Service worker registration failed:', error);
        });
    });
    
    // Add dev helper to check service worker status
    window.checkSW = function() {
      navigator.serviceWorker.getRegistrations().then(registrations => {
        console.log('Service Worker Registrations:', registrations);
        registrations.forEach(reg => {
          console.log('Scope:', reg.scope);
          console.log('Active:', reg.active);
          console.log('Waiting:', reg.waiting);
          console.log('Installing:', reg.installing);
        });
      });
    };
    
    // Helper to force clear all caches
    window.clearAllCaches = function() {
      caches.keys().then(names => {
        names.forEach(name => {
          caches.delete(name);
          console.log('Deleted cache:', name);
        });
        console.log('All caches cleared');
      });
    };
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
        appInit();
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
      return appInit();
    })
    .catch(error => {
      console.error('Failed to initialize IndexedDB:', error);
      appInit();
    });

  // Preload menu data when online to ensure it's cached
  if (navigator.onLine) {
    setTimeout(() => {
      fetchWithOfflineSupport('app-init', 'POST', { message: "Preloading menu data" })
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

  // Login form
  function loadLoginForm() {
    const loginFormHTML = `
      <div class="login-container">
        <div class="login-form">
          <h2 class="form-title">Log in</h2>

          <div id="login-error-msg"></div>

          <div class="input-group">
            <label for="username">Username</label>
            <input id="app-username" type="text" placeholder="Enter your username" required>
          </div>

          <div class="input-group">
            <label for="password">Password</label>
            <input id="app-password" type="password" placeholder="Enter your password" required>
          </div>

          <button type="submit" class="btn login-btn" id="app-login">Log In</button>

          <button type="submit" class="btn" id="start-signup">Sign Up</button>

          <div class="divider"><span>OR</span></div>

          <button type="button" id="google-signin" class="btn google-btn">
            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="Google logo">
            Sign in with Google
          </button>
        </div>
      </div>
    `;
    
    // Create a container for the login form
    const loginContainer = document.createElement('div');
    loginContainer.innerHTML = loginFormHTML;
    
    // Append to appRoot instead of replacing
    appRoot.appendChild(loginContainer);

    const appLogin = document.querySelector('#app-login');
    const appUsernameInput = document.querySelector('#app-username');
    const appPasswordInput = document.querySelector('#app-password');
    const loginErrorMsg = document.querySelector('#login-error-msg');
    appLogin.addEventListener('pointerup', async () =>{
      loader.style.display = 'block';
      try {
        const url = `${window.api_url}app-login`;
        const options = {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            username: appUsernameInput.value,
            password: appPasswordInput.value
          })
        };

        const response = await fetch(url, options);
        if (!response.ok) {
          throw new Error(`Network error (${response.status})`);
        }

        const dataResponse = await response.json();
        if (dataResponse.success) {
          loader.style.display = 'none';
          loginUser(dataResponse.auth_id);
          scrollToTop();
        }
        else {
          loginErrorMsg.innerText = dataResponse.message;
          loader.style.display = 'none';
        }
      } 
      catch (err) {
        loader.style.display = 'none';
        console.warn('the network failed:', err);
      }
    });

    const startSignup = document.querySelector('#start-signup');
    startSignup.addEventListener('pointerup', async ()=>{
      appRoot.innerHTML = '';
      await getView('signup');
      window.initializeSignup();
      scrollToTop();
    });

    handleGoogleAuth();
  }

  function loginUser(user_id) {
      // Set auth_id
      window.auth_id = user_id;
      window.navData = { auth_id: user_id };

      // Save to IndexedDB
      saveToIndexedDB('user-auth', {}, { auth_id: user_id })
        .then(async () => {
          console.log('User authenticated:', user_id);
          
          // Update navigation
          setupNavigation();

          appRoot.innerHTML = '';

          // Load dashboard view
          await getView('dashboard');
          scrollToTop();
          window.resetDashboard();
          // Dashboard.js is already loaded, but its DOMContentLoaded won't fire again
          // So we need to manually initialize it
          setTimeout(() => {
              if (window.initializeDashboard) {
                  window.initializeDashboard();
              } else {
                  console.error('Dashboard initialization function not found');
              }
          }, 100);
        })
        .catch(error => {
          console.error('Failed to save auth:', error);
        });
  }
  window.loginUser = loginUser;

  function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

});