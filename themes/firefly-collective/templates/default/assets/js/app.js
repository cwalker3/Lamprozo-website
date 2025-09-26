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

  // Centralized logging function
  function appLog(message, ...args) {
    return; // Temporarily disabled
    // if (devMode || !window.location.hostname.includes('localhost')) {
    //   console.log(`[APP] ${message}`, ...args);
    // }
  }

  // Initialize IndexedDB
  function initIndexedDB() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      
      request.onerror = event => {
        appLog('IndexedDB error:', event.target.error);
        reject('Could not open IndexedDB');
      };
      
      request.onsuccess = event => {
        db = event.target.result;
        appLog('IndexedDB initialized successfully');
        resolve(db);
      };
      
      request.onupgradeneeded = event => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          const store = db.createObjectStore(STORE_NAME, { keyPath: 'id' });
          store.createIndex('endpoint', 'endpoint', { unique: false });
          store.createIndex('timestamp', 'timestamp', { unique: false });
          appLog('Object store created');
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
        appLog('Data saved to IndexedDB:', id);
        resolve();
      };
      request.onerror = event => {
        appLog('Error saving to IndexedDB:', event.target.error);
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
        appLog('Data retrieved from IndexedDB:', result);
          resolve(result.data);
        } else {
          reject('No matching data found in IndexedDB');
        }
      };
      request.onerror = event => {
        appLog('Error retrieving from IndexedDB:', event.target.error);
        reject(event.target.error);
      };
    });
  }

  // Fetch API with offline support and 1-hour cache strategy (not in dev mode)
  async function fetchWithOfflineSupport(endpoint, method = 'GET', params = {}) {
    const url = `${api_url}${endpoint}`;
    
    if (devMode) {
      debugLog('DevMode: network-only fetch:', url);
      try {
        const options = {
          method,
          headers: { 'Content-Type': 'application/json' },
          cache: 'no-store'
        };
        if (method === 'POST') options.body = JSON.stringify(params);

        const response = await fetch(url, options);
        if (!response.ok) {
          throw new Error(`Network error (${response.status})`);
        }
        
        const data = await response.json();
        
        // Check for offline response
        if (data._offline) {
          throw new Error('Offline response detected');
        }
        
        setAuthId(data.auth_id);
        setAppData(data);
        
        // Save to IndexedDB
        await saveToIndexedDB(endpoint, params, data);
        return data;
      } catch (err) {
        appLog('DevMode network failed, falling back to cache:', err);
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
        appLog('Could not save to IndexedDB:', e)
      );
      return data;
    } catch (err) {
      appLog('ProdMode network failed, falling back to cache:', err);
      return getFromIndexedDB(endpoint, params); // final fallback
    }
  }

  function setAuthId(id) {
    window.navData = { auth_id: id };
    window.auth_id = id;
  }

  function setAppData(data) {
    window.api_url              = data.api_url;
    window.nonce                = data.nonce;
    window.http_host            = data.http_host;
    window.theme_path           = data.theme_path;
    window.template_path        = data.template_path;
    window.app_page_title       = data.app_page_title;
    window.app_page_html        = data.app_page_html;
    window.subscription_status  = data.subscription_status;
    window.active_template      = data.active_template;
    window.template_assets      = data.template_assets;
    window.templateData         = data.templateData;
    window.third_party          = data.third_party;
    window.dynamic_css          = data.dynamic_css;

    // Notify service worker of active template
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller && data.active_template) {
      navigator.serviceWorker.controller.postMessage({
        action: 'setActiveTemplate',
        template: data.active_template,
        assets: data.template_assets
      });
    }
  }

  // Inject dynamic CSS into the DOM
  function injectDynamicCSS(cssContent) {
    if (!cssContent || cssContent.trim() === '') {
      debugLog('No dynamic CSS to inject');
      return;
    }

    // Remove existing dynamic CSS if it exists
    const existingStyle = document.getElementById('template-custom-properties');
    if (existingStyle) {
      existingStyle.remove();
      debugLog('Removed existing dynamic CSS');
    }

    // Create and inject new style element
    const styleElement = document.createElement('style');
    styleElement.id = 'template-custom-properties';
    styleElement.textContent = cssContent;
    
    // Insert at the beginning of head to ensure it loads before other template CSS
    document.head.insertBefore(styleElement, document.head.firstChild);
    
    debugLog('Dynamic CSS injected successfully');
    appLog('Injected CSS:', cssContent);
  }

  // Load and inject dynamic CSS (with offline fallback)
  async function loadDynamicCSS() {
    let cssContent = null;
    let isFromCache = false;

    // Strategy 1: Use fresh CSS from current app-init (when online)
    if (window.dynamic_css) {
      cssContent = window.dynamic_css;
      debugLog('Using fresh dynamic CSS from app-init');
    }
    
    // Strategy 2: Fallback to IndexedDB cache (when offline or no fresh CSS)
    if (!cssContent) {
      try {
        const cachedData = await getFromIndexedDB('dynamic-css', {});
        if (cachedData && cachedData.dynamic_css) {
          cssContent = cachedData.dynamic_css;
          isFromCache = true;
          debugLog('Using cached dynamic CSS from IndexedDB');
          appLog('App is using cached CSS customizations (offline mode)');
        }
      } catch (e) {
        debugLog('No cached dynamic CSS found');
      }
    }

    // Strategy 3: If still no CSS, try to extract from cached app-init data
    if (!cssContent) {
      try {
        const cachedAppInit = await getFromIndexedDB('app-init', {});
        if (cachedAppInit && cachedAppInit.dynamic_css) {
          cssContent = cachedAppInit.dynamic_css;
          isFromCache = true;
          debugLog('Using dynamic CSS from cached app-init');
        }
      } catch (e) {
        debugLog('No CSS found in cached app-init');
      }
    }

    // Inject whatever CSS we found
    if (cssContent) {
      injectDynamicCSS(cssContent);
      
      // If we got fresh CSS (not from cache), update our cache
      if (!isFromCache) {
        try {
          await saveToIndexedDB('dynamic-css', {}, {
            dynamic_css: cssContent,
            timestamp: Date.now(),
            cached_from: 'fresh_app_init'
          });
          debugLog('Fresh dynamic CSS saved to IndexedDB cache');
        } catch (e) {
          appLog('Failed to cache dynamic CSS:', e);
        }
      }
    } else {
      debugLog('No dynamic CSS available - app will use default styling');
      appLog('No CSS customizations found - using default theme styles');
    }

    return cssContent;
  }

  // Force refresh CSS when coming back online
  async function refreshDynamicCSS() {
    if (!navigator.onLine) {
      debugLog('Cannot refresh CSS - still offline');
      return false;
    }

    try {
      // Fetch fresh app-init to get latest customizations
      const freshData = await fetchWithOfflineSupport('app-init', 'POST');
      
      if (freshData && freshData.dynamic_css) {
        // Update window data
        window.dynamic_css = freshData.dynamic_css;
        
        // Inject the fresh CSS
        injectDynamicCSS(freshData.dynamic_css);
        
        // Update cache
        await saveToIndexedDB('dynamic-css', {}, {
          dynamic_css: freshData.dynamic_css,
          timestamp: Date.now(),
          cached_from: 'refresh_online'
        });
        
        debugLog('Dynamic CSS refreshed successfully');
        appLog('CSS customizations updated from server');
        return true;
      }
    } catch (error) {
      appLog('Failed to refresh dynamic CSS:', error);
      return false;
    }
    
    return false;
  }

  // Add event listener to refresh CSS when coming back online
  window.addEventListener('online', async () => {
    appLog('Connection restored - refreshing customizations...');
    await refreshDynamicCSS();
  });

  // Dynamic asset loading - ONLY template-specific assets
  async function loadTemplateAssets() {
    let templateAssets = window.template_assets;
  
    // If no template assets in memory, try to get from cache
    if (!templateAssets) {
      try {
        const cachedData = await getFromIndexedDB('template-info', {});
        templateAssets = cachedData.template_assets || { css: [], js: [] };
      } catch (e) {
        templateAssets = { css: [], js: [] }; // Fallback
      }
    }

    // Set templateData globally BEFORE loading template scripts
    if (window.templateData) {
      window.templateData = window.templateData;
    } else {
      window.templateData = { success: '1' }; // Fallback
    }

    // Helper function to load CSS
    function loadCSS(href) {
      return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.onload = resolve;
        link.onerror = reject;
        document.head.appendChild(link);
      });
    }

    // Helper function to load JS
    function loadJS(src) {
      return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.defer = true;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
      });
    }

    try {
      // Load template CSS
      if (templateAssets.css && templateAssets.css.length > 0) {
        await Promise.all(templateAssets.css.map(href => loadCSS(href)));
        appLog('Template CSS loaded successfully');
      }

      // Load template JS
      if (templateAssets.js && templateAssets.js.length > 0) {
        await Promise.all(templateAssets.js.map(src => loadJS(src)));
        appLog('Template JS loaded successfully');
      }

    } catch (error) {
      appLog('Some template assets failed to load:', error);
    }
  }

  async function getView(view) {
    const appRoot = document.querySelector('#app-root');

    if (!appRoot) {
      return;
    }

    loader.style.display = 'block';

    try {
      const endpoint = 'app-get-view';
      const params = { view };
      let dataResponse;
      let isOffline = false;
      
      // First, try network
      try {
        const url = `${window.api_url}${endpoint}`;
        const options = {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(params)
        };
        
        if (devMode) {
          options.cache = 'no-store';
        }
        
        const response = await fetch(url, options);
        if (!response.ok) {
          throw new Error(`Network error (${response.status})`);
        }
        
        dataResponse = await response.json();
        
        // Check if this is an offline response from service worker
        if (dataResponse._offline || !dataResponse.success) {
          appLog('Got offline response from service worker, trying IndexedDB');
          throw new Error('Offline response - fallback to IndexedDB');
        }
        
        // Save successful response to IndexedDB
        if (dataResponse.success) {
          await saveToIndexedDB(`view:${view}`, {}, {
            ...dataResponse,
            timestamp: Date.now()
          });
        }
      } catch (networkError) {
        appLog('Network failed or offline, trying IndexedDB:', networkError);
        isOffline = true;
        
        // Try IndexedDB
        try {
          const cachedData = await getFromIndexedDB(`view:${view}`, {});
          dataResponse = cachedData;
          appLog('Loaded view from IndexedDB:', view);
        } catch (cacheError) {
          appLog('View not available in IndexedDB:', cacheError);
          loader.style.display = 'none';
          
          // Show offline message
          appRoot.innerHTML = `
            <div class="offline-message" style="text-align: center; padding: 40px 20px;">
              <h2>This view is not available offline</h2>
              <p>The "${view}" page hasn't been loaded yet while online.</p>
              <p>Please connect to the internet and try again.</p>
              <button onclick="window.location.reload()" class="btn" style="margin-top: 20px;">
                Try Again
              </button>
            </div>
          `;
          return;
        }
      }
      
      // Process the response
      if (dataResponse && dataResponse.success) {
        loader.style.display = 'none';
        
        // Update window variables
        switch (view) {
          case 'dashboard':
            window.theme_path = dataResponse.theme_path;
            window.features = dataResponse.features;
            window.stripeKey = dataResponse.stripeKey;
            window.subscription_status = dataResponse.subscription_status;
            break;
            
          case 'order-history':
            window.apiUrl = dataResponse.apiUrl;
            window.data = dataResponse.data;
            break;
        }

        // Insert HTML
        appRoot.innerHTML = '';
        appRoot.innerHTML = dataResponse.response_html;
      } else {
        throw new Error('Invalid response data');
      }
      
    } catch (err) {
      loader.style.display = 'none';
      appLog('Failed to load view:', err);
      
      // Show error message
      appRoot.innerHTML = `
        <div class="error-message" style="text-align: center; padding: 40px 20px;">
          <h2>Unable to load this view</h2>
          <p>There was an error loading the "${view}" page.</p>
          <button onclick="window.location.reload()" class="btn" style="margin-top: 20px;">
            Refresh Page
          </button>
        </div>
      `;
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
          appLog(`Nav item clicked: ${navSlug}`);
          closeWebsiteMenu(true);  // Use force parameter
          loadContent(navSlug);
        });
      });
      
      setupNavigation();
      updateNavVisibility();
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
        
        // If offline, just clear local data
        if (!navigator.onLine) {
          loader.style.display = 'none';
          clearUser();
        }

        else {
          // Logout endpoint
          fetch(`${window.api_url}app-logout/?auth_id=${window.auth_id}`, {
            headers: {
                'Content-Type': 'application/json'
            }
          }).then(response => response.json())
          .then(data => {
            if (data.logout) {
              
              // Also reset dashboard if it has a similar pattern
              if (window.resetDashboard) {
                window.resetDashboard();
              }

              clearUser();
              loader.style.display = 'none';
            }
          })
          .catch(error => {
            appLog('Error logging out:', error);
            loader.style.display = 'none';
          });
        }
        scrollToTop();
        break;

      case 'dashboard':
        // Only reset if dashboard was already initialized (meaning we're navigating back)
        if (window.dashboardInitialized && window.resetDashboard && typeof window.resetDashboard === 'function') {
          window.resetDashboard();
        }
        await getView('dashboard');
        if (document.getElementById('features-container')) {
          if (window.initializeDashboard && typeof window.initializeDashboard === 'function') {
            window.initializeDashboard();
          }
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

  // Clear user from system and load form
  function clearUser() {
    window.auth_id = null;
    window.navData = null;
    const transaction = db.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    store.delete('user-auth:{}');

    // Hide profile dropdown container
    const profileContainer = document.getElementById('profile-dropdown-container');
    if (profileContainer) {
      profileContainer.style.display = 'none';
    }

    updateNavVisibility();
    appRoot.innerHTML = '';
    loadAppTitleAndAppHTML();
    loadLoginForm();
    scrollToTop();
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
      appLog('Navigation elements not found', {
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
      appLog('Elements missing for openWebsiteMenu');
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
      appLog('Elements missing for closeWebsiteMenu');
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
    // Check if we're in PWA/app mode (converted menu items are .app-nav divs)
    const appNavItems = document.querySelectorAll('.app-nav');
    const isPWA = appNavItems.length > 0;

    if (isPWA) {
      // PWA navigation - use .app-nav divs
      const signupBtn = document.querySelector('.app-nav#signup, .app-nav#sign-up');
      const orderHistoryBtn = document.querySelector('.app-nav#order-history');
      const dashboardBtn = document.querySelector('.app-nav#dashboard');
      const backToWebsiteBtn = document.querySelector('.app-nav#back-to-website');
      const logoutBtn = document.querySelector('.app-nav#log-out');
      const loginBtn = document.querySelector('.app-nav#log-in, .app-nav#login');

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
    } else {
      // Regular website navigation - use li selectors
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

    // Mark that auth state has been checked and nav filtering is complete
    // Add small delay to ensure DOM is fully processed
    setTimeout(() => {
      document.body.classList.add('auth-checked');
    }, 50);
  }

  // Initialize the app
  async function appInit() {
	debugLog('Initializing App...');
    loader.style.display = 'block';
    
    // First, try to restore auth from IndexedDB - with better error handling
    return getFromIndexedDB('user-auth', {})
      .then(authData => {
        if (authData && authData.auth_id) {
          appLog('Restored auth from IndexedDB:', authData.auth_id);
          window.auth_id = authData.auth_id;
          window.navData = { auth_id: authData.auth_id };

          // Update profile dropdown visibility since auth was restored
          if (typeof window.updateProfileDropdownVisibility === 'function') {
            window.updateProfileDropdownVisibility();
          }

          return true; // Auth restored successfully
        }
        return false; // No auth found
      })
      .catch(() => {
        appLog('No saved auth found');
        return false; // No auth found
      })
      .then((authRestored) => {
        // If offline and no cached app-init data, use what we have
        if (!navigator.onLine) {
          return getFromIndexedDB('app-init', {})
            .then(cachedData => {
              appLog('Using cached app-init data');
              // Set the app data from cache
              if (cachedData) {
                setAuthId(cachedData.auth_id || window.auth_id);
                setAppData(cachedData);
              }
              return cachedData;
            })
            .catch(() => {
              appLog('No cached app-init, creating minimal data');
              // Return minimal data structure
              return {
                success: true,
                menu_html: null,
                app_page_title: 'App',
                app_page_html: 'Welcome to the app'
              };
            });
        }
        
        // Online - fetch normally
        return fetchWithOfflineSupport('app-init', 'POST');
      })
      .then(async data => {
        if (!data.success) throw new Error('App init failed');
        
        // Load dynamic CSS FIRST - before template assets
        await loadDynamicCSS();
        
        // Load template assets dynamically
        await loadTemplateAssets();
        
        // Insert menu into DOM
        const menuInserted = insertMenuIntoDOM(data.menu_html);
        
        // If menu wasn't inserted but we have cached menu, try that
        if (!menuInserted && !navigator.onLine) {
          try {
            const cachedMenu = await getFromIndexedDB('menu-html', {});
            if (cachedMenu && cachedMenu.menu_html) {
              insertMenuIntoDOM(cachedMenu.menu_html);
            }
          } catch (e) {
            appLog('No cached menu available');
          }
        }
        
        // Always save menu HTML separately for offline use
        if (data.menu_html) {
          await saveToIndexedDB('menu-html', {}, {
            menu_html: data.menu_html,
            timestamp: Date.now()
          });
        }

        // Save app page data to IndexedDB
        await saveToIndexedDB('app-page-data', {}, {
          app_page_title: data.app_page_title,
          app_page_html: data.app_page_html
        });

        loadAppTitleAndAppHTML();

        // Dashboard (or log in form if not logged in)
        if (!window.auth_id) {
          loadLoginForm();
        } else {
          await getView('dashboard');
          if (window.initializeDashboard) {
            window.initializeDashboard();
          }
        }
        
        window.gapiDomain = data.gapiDomain;
        loader.style.display = 'none';
      })
      .catch(async error => {
        appLog('Failed to load app:', error);
        loader.style.display = 'none';
        
        // Try to load cached data if available
        try {
          const cachedMenu = await getFromIndexedDB('menu-html', {});
          const cachedAppData = await getFromIndexedDB('app-page-data', {});
          
          // Load dynamic CSS even when offline
          await loadDynamicCSS();
          
          // Load assets even when offline
          await loadTemplateAssets();
          
          if (cachedMenu && cachedMenu.menu_html) {
            insertMenuIntoDOM(cachedMenu.menu_html);
          }
          
          if (cachedAppData) {
            window.app_page_title = cachedAppData.app_page_title;
            window.app_page_html = cachedAppData.app_page_html;
          }
          
          loadAppTitleAndAppHTML();
          
          if (!window.auth_id) {
            loadLoginForm();
          } else {
            // Try to load cached dashboard
            await getView('dashboard');
            if (window.initializeDashboard) {
              window.initializeDashboard();
            }
          }
        } catch (e) {
          appLog('No cached data available:', e);
          
          // Still try to load CSS and assets
          await loadDynamicCSS();
          await loadTemplateAssets();
          insertFallbackMenu();
        }
      });
  }

  // App page title and HTML
  function loadAppTitleAndAppHTML() {
    if (!window.app_page_title || !window.app_page_html) {
      appLog('App page data not available');
      return;
    }
    
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
        .register('/wp-content/themes/firefly-collective/templates/default/service-worker.js',
          { scope: '/wp-content/themes/firefly-collective/templates/default/' }
        )
        .then(function(registration) {
          appLog('Service worker registration succeeded:', registration);
          
          // Check for updates immediately
          registration.update();
          
          // Listen for updates
          registration.addEventListener('updatefound', () => {
            appLog('Service worker update found');
            const newWorker = registration.installing;
            
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New service worker ready - but DON'T auto-reload
                appLog('New service worker ready! Refresh to get updates.');
                // Just skip waiting, don't reload
                newWorker.postMessage({ action: 'skipWaiting' });
              }
            });
          });
          
          // DON'T auto-reload on controller change - let user control refreshes
          navigator.serviceWorker.addEventListener('controllerchange', () => {
            appLog('Service worker updated. New version will be used on next refresh.');
          });
          
          // Check service worker periodically (but don't auto-reload)
          setInterval(() => {
            registration.update();
          }, 60000); // Check every minute
        })
        .catch(function(error) {
          appLog('Service worker registration failed:', error);
        });
    });
    
    // Add dev helper to check service worker status
    window.checkSW = function() {
      navigator.serviceWorker.getRegistrations().then(registrations => {
        appLog('Service Worker Registrations:', registrations);
        registrations.forEach(reg => {
          appLog('Scope:', reg.scope);
          appLog('Active:', reg.active);
          appLog('Waiting:', reg.waiting);
          appLog('Installing:', reg.installing);
        });
      });
    };
    
    // Helper to force clear all caches
    window.clearAllCaches = function() {
      caches.keys().then(names => {
        names.forEach(name => {
          caches.delete(name);
          appLog('Deleted cache:', name);
        });
        appLog('All caches cleared');
      });
    };
  }

  // Check for network connection status
  function updateOnlineStatus() {
    const condition = navigator.onLine ? "online" : "offline";
    appLog(`Connection status: ${condition}`);
    
    // Control the offline indicator that's already in the HTML
    const offlineIndicator = document.querySelector('.offline-indicator');
    
    if (condition === "offline") {
      document.body.classList.add('is-offline');
      if (offlineIndicator) {
        offlineIndicator.style.display = 'block';
      }
    } else {
      document.body.classList.remove('is-offline');
      if (offlineIndicator) {
        offlineIndicator.style.display = 'none';
      }
      
      // When coming back online, refresh CSS customizations after a short delay
      setTimeout(async () => {
        await refreshDynamicCSS();
      }, 1000);
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

  // Preload views when online to ensure offline availability
  if (navigator.onLine) {
    setTimeout(() => {
      const viewsToPreload = ['dashboard', 'order-history', 'signup'];
      
      viewsToPreload.forEach((view, index) => {
        // Stagger the preloading to avoid overwhelming the server
        setTimeout(() => {
          // Only preload if user is authenticated (for protected views)
          if (view === 'signup' || window.auth_id) {
            appLog(`Preloading view: ${view}`);
            
            // Use a hidden fetch to cache the view
            fetch(`${window.api_url}app-get-view`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ view })
            })
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                saveToIndexedDB(`view:${view}`, {}, {
                  ...data,
                  timestamp: Date.now()
                });
                appLog(`View preloaded: ${view}`);
              }
            })
            .catch(() => appLog(`Failed to preload view: ${view}`));
          }
        }, index * 2000); // 2 second delay between each preload
      });
    }, 10000); // Start preloading 10 seconds after app init
  }

  // Subscription status check function
  async function setSubscriptionStatus(forceRefresh = false) {
    const cacheKey = 'subscription-status';
    
    // If offline and not forcing refresh, return cached status
    if (!navigator.onLine && !forceRefresh) {
      try {
        const cached = await getFromIndexedDB(cacheKey, {});
        return cached;
      } catch (e) {
        return { has_active_subscription: false, status: 'not_paid' };
      }
    }
    
    try {
      // Check subscription status via API
      const response = await fetchWithOfflineSupport('check-subscription-status', 'GET');
      
      if (response.success) {
        // Cache the result
        await saveToIndexedDB(cacheKey, {}, {
          ...response,
          timestamp: Date.now()
        });
        
        // Store in window for quick access
        window.subscriptionStatus = response;
        
        return response;
      }
    } catch (error) {
      appLog('Failed to check subscription status:', error);
      
      // Try to get cached status
      try {
        const cached = await getFromIndexedDB(cacheKey, {});
        return cached;
      } catch (e) {
        return { has_active_subscription: false, status: 'not_paid' };
      }
    }
    
    return { has_active_subscription: false, status: 'not_paid' };
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
        appLog('the network failed:', err);
      }
    });

    const startSignup = document.querySelector('#start-signup');
    startSignup.addEventListener('pointerup', async ()=>{
      const appRoot = document.querySelector('#app-root');
      appRoot.innerHTML = '';
      await getView('signup');
      window.initializeSignup();
      scrollToTop();
    });

    handleGoogleAuth();
  }

  // Track if login is in progress to prevent duplicate calls
  let loginInProgress = false;

  function loginUser(user_id) {

      // Prevent multiple simultaneous login attempts
      if (loginInProgress) {
          return;
      }
      loginInProgress = true;

      // Set auth_id
      window.auth_id = user_id;
      window.navData = { auth_id: user_id };

      // Save to IndexedDB
      saveToIndexedDB('user-auth', {}, { auth_id: user_id })
        .then(async () => {
          appLog('User authenticated:', user_id);
          
          // Update navigation
          setupNavigation();
          updateNavVisibility();

          // Initialize profile dropdown after a short delay to ensure DOM is ready
          setTimeout(() => {
            // Reset the initialization flag to allow re-initialization after login
            window.profileDropdownInitialized = false;

            if (typeof window.initProfileDropdown === 'function') {
              window.initProfileDropdown();
            } else if (typeof initProfileDropdown === 'function') {
              initProfileDropdown();
            }
          }, 100);

          appRoot.innerHTML = '';

          // Load dashboard view - no need to reset on initial login
          await getView('dashboard');

          // Add delay to ensure DOM is fully rendered before initializing
          setTimeout(() => {
            const featuresContainer = document.getElementById('features-container');
            if (featuresContainer) {
              if (window.initializeDashboard && typeof window.initializeDashboard === 'function') {
                window.initializeDashboard();
              } else {
              }
              scrollToTop();
            } else {
            }
          }, 100);

          // Reset the login flag after everything is complete
          setTimeout(() => {
            loginInProgress = false;
          }, 200);
        })
        .catch(error => {
          appLog('Failed to save auth:', error);
          loginInProgress = false; // Reset on error
        });
  }
  window.loginUser = loginUser;
  window.getView = getView;
  window.loadContent = loadContent;

  function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

});