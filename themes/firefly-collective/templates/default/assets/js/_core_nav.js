// template/assets/js/_core_nav.js

document.addEventListener('DOMContentLoaded', function () {
    // Initialize navigation system
    initNavigation();

    // Initialize consolidated overlay menu if it exists
    initOverlayMenu();

    // Initialize profile dropdown
    initProfileDropdown();
});

// Extract initialization into a function that can be called after dynamic menu insertion
function initNavigation() {
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn'); // legacy — optional
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');

    // Skip if elements don't exist yet
    if (!hamburger || !nav || !backdrop) {
        console.log('Navigation elements not found, skipping initialization');
        return;
    }

    // Handle Submenus ONLY for hamburger menu (not overlay menu) - IMPROVED for nested menus
    const menuItems = nav.querySelectorAll('.menu-item-has-children');
    menuItems.forEach(function (menuItem) {
        const submenu = menuItem.querySelector('.sub-menu');
        
        // Check if expand icon already exists (to avoid duplicates)
        let expandIcon = menuItem.querySelector('.expand-icon');
        if (!expandIcon) {
            expandIcon = document.createElement('div');
            expandIcon.classList.add('expand-icon');
            expandIcon.textContent = '+';
            
            // Append to the end of the menu item instead of inserting at the beginning
            menuItem.appendChild(expandIcon);
        }

        const link = menuItem.querySelector('a');
        
        // Remove existing click listeners to avoid duplicates
        const newLink = link.cloneNode(true);
        link.parentNode.replaceChild(newLink, link);
        
        newLink.addEventListener('click', function (e) {
            e.preventDefault();
            expandMenu(submenu, expandIcon);
        });
    });

    // Clear any existing event listeners to prevent duplicates
    hamburger.replaceWith(hamburger.cloneNode(true));
    if (closeNavBtn) closeNavBtn.replaceWith(closeNavBtn.cloneNode(true));

    // Get fresh references after cloning
    const newHamburger = document.getElementById('hamburger');
    const newCloseNavBtn = document.getElementById('close-nav-btn');

    // Hamburger is now a single toggle — same button morphs into an X
    // when the menu is open, so clicking it either opens or closes.
    newHamburger.addEventListener('click', function (event) {
        event.stopImmediatePropagation();
        if (document.body.classList.contains('menu-open')) {
            closeWebsiteMenu();
        } else {
            openWebsiteMenu();
        }
    });
    if (newCloseNavBtn) {
        newCloseNavBtn.addEventListener('click', function (event) {
            event.stopImmediatePropagation();
            closeWebsiteMenu();
        });
    }
    backdrop.addEventListener('click', function (event) {
        event.stopImmediatePropagation();
        closeWebsiteMenu();
    });

    // Landing page only: dim the hamburger once the user scrolls past the
    // hero so it recedes into the content, then restore full opacity on
    // return-to-top. Other pages keep the hamburger at a constant opacity.
    if (document.body.classList.contains('page-home')) {
        const scrollHamburger = document.getElementById('hamburger');
        if (scrollHamburger) {
            let ticking = false;
            const syncScrollState = function () {
                scrollHamburger.classList.toggle('scrolled-dim', window.scrollY > 80);
                ticking = false;
            };
            window.addEventListener('scroll', function () {
                if (!ticking) {
                    window.requestAnimationFrame(syncScrollState);
                    ticking = true;
                }
            }, { passive: true });
            syncScrollState();
        }
    }

    // Handle authentication-based menu visibility for hamburger menu
    handleAuthMenuVisibility('body > nav .website-menu ul > li');

    function openWebsiteMenu() {
        const hamburgerEl = document.getElementById('hamburger');

        nav.style.display = 'grid';
        nav.style.visibility = 'visible';  // Override CSS hidden state when explicitly opening
        nav.style.opacity = '1';  // Override CSS opacity when explicitly opening
        backdrop.style.display = 'block';
        backdrop.style.pointerEvents = 'auto';
        nav.classList.remove('slide-out');
        backdrop.classList.remove('fade');

        // CSS handles the bar-to-X morph — we toggle `is-open` on the
        // hamburger itself AND `menu-open` on the body so either trigger
        // on its own is enough to drive the animation.
        if (hamburgerEl) {
            hamburgerEl.setAttribute('aria-expanded', 'true');
            hamburgerEl.classList.add('is-open');
        }
        document.body.classList.add('menu-open');

        // Ensure auth check has run to show menu items
        if (!document.body.classList.contains('auth-checked')) {
            document.body.classList.add('auth-checked');
        }
    }

    function closeWebsiteMenu() {
        const hamburgerEl = document.getElementById('hamburger');

        nav.classList.add('slide-out');
        backdrop.classList.add('fade');
        backdrop.style.pointerEvents = 'none';

        if (hamburgerEl) {
            hamburgerEl.setAttribute('aria-expanded', 'false');
            hamburgerEl.classList.remove('is-open');
        }
        document.body.classList.remove('menu-open');
    }

    function expandMenu(menuItemEle, expandIcon) {
        
        if (menuItemEle.style.maxHeight && menuItemEle.style.maxHeight !== '0px' && menuItemEle.style.maxHeight !== '') {
            // Collapse the menu
            menuItemEle.style.maxHeight = '0px';
            expandIcon.textContent = '+';
            
            // Also collapse any expanded child submenus
            const childSubmenus = menuItemEle.querySelectorAll('.sub-menu');
            childSubmenus.forEach(childSubmenu => {
                if (childSubmenu.style.maxHeight && childSubmenu.style.maxHeight !== '0px') {
                    childSubmenu.style.maxHeight = '0px';
                    const childIcon = childSubmenu.parentElement.querySelector('.expand-icon');
                    if (childIcon) childIcon.textContent = '+';
                }
            });
        } else {
            // Expand the menu
            expandIcon.textContent = '-';
            
            // Calculate height by temporarily removing constraints
            menuItemEle.style.maxHeight = 'none';
            const naturalHeight = menuItemEle.scrollHeight;
            
            // Reset to 0 and then animate to natural height
            menuItemEle.style.maxHeight = '0px';
            
            // Use requestAnimationFrame to ensure the 0px is applied before animation
            requestAnimationFrame(() => {
                menuItemEle.style.maxHeight = naturalHeight + 'px'
                
                // Update parent heights after animation completes
                setTimeout(() => {
                    updateParentHeights(menuItemEle);
                }, 400); // Match the CSS transition duration
            });
        }
    }
    
    // Simplified parent height update function
    function updateParentHeights(element) {
        let parentSubmenu = element.parentElement;
        
        // Traverse up to find parent submenus
        while (parentSubmenu) {
            if (parentSubmenu.classList.contains('sub-menu')) {
                // Calculate new height for parent
                const currentMaxHeight = parentSubmenu.style.maxHeight;
                if (currentMaxHeight && currentMaxHeight !== '0px' && currentMaxHeight !== '') {
                    // Parent is expanded, so recalculate its height
                    parentSubmenu.style.maxHeight = 'none';
                    const newHeight = parentSubmenu.scrollHeight;
                    parentSubmenu.style.maxHeight = newHeight + 'px';
                }
            }
            
            // Move up to next parent
            parentSubmenu = parentSubmenu.parentElement;
            
            // Stop at the main menu level
            if (parentSubmenu && (parentSubmenu.classList.contains('website-menu') || parentSubmenu.tagName === 'NAV')) {
                break;
            }
        }
    }
}

// Initialize consolidated overlay menu functionality
function initOverlayMenu() {
    const overlayMenuContainer = document.getElementById('overlay-menu-container');
    
    if (!overlayMenuContainer) {
        return; // Overlay menu not enabled
    }
    
    // Handle authentication-based menu visibility for consolidated overlay menu
    handleAuthMenuVisibility('.overlay-menu > li');
    
    // Overlay menu uses pure CSS for dropdowns - no JavaScript needed!
}

// Shared function to handle authentication-based menu item visibility
function handleAuthMenuVisibility(selector) {
    // Get all top-level menu items
    const allTopLevelItems = document.querySelectorAll(selector);
    const topLevelArray = Array.from(allTopLevelItems).filter(item => {
        // Only target direct children, not submenu items
        return !item.closest('.sub-menu');
    });

    // Find specific menu items by their text content
    let loginBtn = null;
    let signupBtn = null;
    let backToWebsiteBtn = null;

    topLevelArray.forEach(item => {
        const text = item.textContent.trim();
        if (text === 'Log In' || text === 'Login') {
            loginBtn = item;
        } else if (text === 'Signup' || text === 'Sign Up') {
            signupBtn = item;
        } else if (text === 'Back to Website') {
            backToWebsiteBtn = item;
        }
    });

    // Use CSS classes instead of inline styles so !important rules work correctly
    if (typeof navData !== 'undefined' && navData.auth_id) {
        // User is logged IN — hide login/signup
        if (loginBtn) loginBtn.classList.add('auth-hide');
        if (signupBtn) signupBtn.classList.add('auth-hide');
        if (backToWebsiteBtn) backToWebsiteBtn.classList.remove('auth-hide');
    } else {
        // User is logged OUT — hide back-to-website
        if (loginBtn) loginBtn.classList.remove('auth-hide');
        if (signupBtn) signupBtn.classList.remove('auth-hide');
        if (backToWebsiteBtn) backToWebsiteBtn.classList.add('auth-hide');
    }

    // Mark auth check as complete so CSS can show menu items
    document.body.classList.add('auth-checked');
}

// Close overlay submenus when clicking outside (optional enhancement)
document.addEventListener('click', function(e) {
    const overlayMenu = document.querySelector('.overlay-menu');
    if (overlayMenu && !overlayMenu.contains(e.target)) {
        // Pure CSS handles all hover states - no manual hiding needed
    }
});

// Handle keyboard navigation for accessibility
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Pure CSS handles all hover states - no manual hiding needed
    }
});

// Initialize profile dropdown functionality
function initProfileDropdown() {
    // Prevent multiple initializations
    if (window.profileDropdownInitialized) {
        return;
    }

    const profileButton = document.getElementById('profile-button');
    const profileDropdown = document.getElementById('profile-dropdown');
    const profileContainer = document.getElementById('profile-dropdown-container');

    if (!profileButton || !profileDropdown || !profileContainer) {
        return; // Elements don't exist
    }

    // Mark as initialized
    window.profileDropdownInitialized = true;

    // For PWA, show container if user is authenticated
    if (window.auth_id || (typeof navData !== 'undefined' && navData.auth_id)) {
        profileContainer.style.display = 'block';
    } else {
        profileContainer.style.display = 'none';
    }

    // Ensure dropdown starts in closed state
    profileDropdown.setAttribute('aria-hidden', 'true');
    profileButton.setAttribute('aria-expanded', 'false');

    // Remove any existing event listeners by cloning
    const newProfileButton = profileButton.cloneNode(true);
    profileButton.parentNode.replaceChild(newProfileButton, profileButton);

    // Get fresh reference after cloning
    const freshProfileButton = document.getElementById('profile-button');

    // Toggle dropdown on button click
    freshProfileButton.addEventListener('click', function(e) {
        e.stopPropagation();
        const currentDropdown = document.getElementById('profile-dropdown');
        const isOpen = currentDropdown.getAttribute('aria-hidden') === 'false';

        if (isOpen) {
            closeProfileDropdown();
        } else {
            openProfileDropdown();
        }
    });

    // Close dropdown when clicking outside (only add once)
    if (!window.profileDropdownGlobalListeners) {
        window.profileDropdownGlobalListeners = true;

        document.addEventListener('click', function(e) {
            const profileContainer = document.getElementById('profile-dropdown-container');
            if (profileContainer && !profileContainer.contains(e.target)) {
                closeProfileDropdown();
            }
        });

        // Close dropdown on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProfileDropdown();
            }
        });
    }

    // Handle dropdown links - convert to app navigation if on /app pag
    if (websiteApp || isPWA) {
        // Simply remove href and add click handlers
        const profileLinks = profileDropdown.querySelectorAll('.profile-dropdown-item');
        profileLinks.forEach(link => {
            const href = link.getAttribute('href');

            // Remove the href so it's not a link anymore
            link.removeAttribute('href');

            // Add click handler
            link.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                closeProfileDropdown();

                // Use the same functions as sidebar nav
                if (href === '/logout') {
                    window.loadContent('log-out');
                } else if (href === '/dashboard') {
                    // Reset dashboard if function exists
                    if (window.resetDashboard && typeof window.resetDashboard === 'function') {
                        window.resetDashboard();
                    }
                    await window.getView('dashboard');
                    if (document.getElementById('features-container')) {
                        if (window.initializeDashboard && typeof window.initializeDashboard === 'function') {
                            window.initializeDashboard();
                        }
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                } else if (href === '/order-history') {
                    await window.getView('order-history');
                    // initOrdersApp is called in the original loadContent - check if it exists
                    if (typeof initOrdersApp === 'function') {
                        initOrdersApp();
                    }
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }
    // For regular website, links work normally without any special handling

    function openProfileDropdown() {
        const currentDropdown = document.getElementById('profile-dropdown');
        const currentButton = document.getElementById('profile-button');
        currentDropdown.setAttribute('aria-hidden', 'false');
        currentButton.setAttribute('aria-expanded', 'true');
    }

    function closeProfileDropdown() {
        const currentDropdown = document.getElementById('profile-dropdown');
        const currentButton = document.getElementById('profile-button');
        currentDropdown.setAttribute('aria-hidden', 'true');
        currentButton.setAttribute('aria-expanded', 'false');
    }
}

// Function to update profile dropdown visibility based on auth state
function updateProfileDropdownVisibility() {
    const profileContainer = document.getElementById('profile-dropdown-container');
    if (!profileContainer) return;

    // Mark that auth state has been checked
    document.body.classList.add('auth-checked');

    // Check auth state
    if (window.auth_id || (typeof window.navData !== 'undefined' && window.navData && window.navData.auth_id)) {
        profileContainer.style.display = 'block';
    } else {
        profileContainer.style.display = 'none';
    }
}

// Export for PWA to call after dynamic menu load
if (typeof window !== 'undefined') {
    window.initProfileDropdown = initProfileDropdown;
    window.updateProfileDropdownVisibility = updateProfileDropdownVisibility;
}