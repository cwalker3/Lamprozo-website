// theme/assets/js/_core_nav.js

document.addEventListener('DOMContentLoaded', function () {
    // Initialize navigation system - this function can be called from app.js
    initNavigation();
    
    // Initialize overlay menu if it exists
    initOverlayMenu();
});

// Extract initialization into a function that can be called after dynamic menu insertion
function initNavigation() {
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const body = document.body;

    // Skip if elements don't exist yet
    if (!hamburger || !closeNavBtn || !nav || !backdrop) {
        console.log('Navigation elements not found, skipping initialization');
        return;
    }

    // Handle Submenus ONLY for hamburger menu (not overlay menu)
    const menuItems = nav.querySelectorAll('.menu-item-has-children');
    menuItems.forEach(function (menuItem) {
        const submenu = menuItem.querySelector('.sub-menu');
        const expandIcon = document.createElement('div');
        expandIcon.classList.add('expand-icon');
        expandIcon.textContent = '+';
        menuItem.insertBefore(expandIcon, menuItem.firstChild);

        const link = menuItem.querySelector('a');
        link.addEventListener('click', function (e) {
            e.preventDefault();
            expandMenu(submenu, expandIcon);
        });
    });

    hamburger.addEventListener('click', function (event) {
        event.stopImmediatePropagation();
        openWebsiteMenu();
    });
    closeNavBtn.addEventListener('click', function (event) {
        event.stopImmediatePropagation();
        closeWebsiteMenu();
    });
    backdrop.addEventListener('click', function (event) {
        event.stopImmediatePropagation();
        closeWebsiteMenu();
    });

    // Handle authentication-based menu visibility for hamburger menu
    handleAuthMenuVisibility('body > nav ul > li');

    function openWebsiteMenu() {
        nav.style.display = 'grid';
        backdrop.style.display = 'block';
        backdrop.style.pointerEvents = 'auto';
        hamburger.style.display = 'none';
        closeNavBtn.style.display = 'block';
        nav.classList.remove('slide-out');
        backdrop.classList.remove('fade');
    }

    function closeWebsiteMenu() {
        nav.classList.add('slide-out');
        backdrop.classList.add('fade');
        backdrop.style.pointerEvents = 'none';
        hamburger.style.display = 'block';
        closeNavBtn.style.display = 'none';
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
}

// Initialize overlay menu functionality
function initOverlayMenu() {
    const overlayMenuContainer = document.getElementById('overlay-menu-container');
    
    if (!overlayMenuContainer) {
        return; // Overlay menu not enabled
    }
    
    // Handle authentication-based menu visibility for overlay menu
    handleAuthMenuVisibility('.overlay-menu > li');
    
    // Overlay menu uses pure CSS - no JavaScript needed for dropdowns!
}

// Shared function to handle authentication-based menu item visibility
function handleAuthMenuVisibility(selector) {
    // Make sure navData exists before using it
    if (typeof navData !== 'undefined' && navData.auth_id) {
        // User is logged IN
        const signupBtn = document.querySelector(selector + ':nth-last-of-type(5)');
        const orderHistoryBtn = document.querySelector(selector + ':nth-last-of-type(4)');
        const dashboardBtn = document.querySelector(selector + ':nth-last-of-type(3)');
        const logoutBtn = document.querySelector(selector + ':nth-last-of-type(2)');
        const loginBtn = document.querySelector(selector + ':last-of-type');
        
        // Hide signup and login, show authenticated user items
        if (signupBtn) signupBtn.style.display = 'none';
        if (loginBtn) loginBtn.style.display = 'none';
        if (orderHistoryBtn) orderHistoryBtn.style.display = 'list-item';
        if (dashboardBtn) dashboardBtn.style.display = 'list-item';
        if (logoutBtn) logoutBtn.style.display = 'list-item';
    } else {
        // User is logged OUT
        const signupBtn = document.querySelector(selector + ':nth-last-of-type(5)');
        const orderHistoryBtn = document.querySelector(selector + ':nth-last-of-type(4)');
        const dashboardBtn = document.querySelector(selector + ':nth-last-of-type(3)');
        const logoutBtn = document.querySelector(selector + ':nth-last-of-type(2)');
        const loginBtn = document.querySelector(selector + ':last-of-type');
        
        // Show signup and login, hide authenticated user items
        if (signupBtn) signupBtn.style.display = 'list-item';
        if (loginBtn) loginBtn.style.display = 'list-item';
        if (orderHistoryBtn) orderHistoryBtn.style.display = 'none';
        if (dashboardBtn) dashboardBtn.style.display = 'none';
        if (logoutBtn) logoutBtn.style.display = 'none';
    }
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