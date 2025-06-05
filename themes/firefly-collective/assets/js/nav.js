// theme/assets/js/nav.js

document.addEventListener('DOMContentLoaded', function () {
    // Initialize navigation system - this function can be called from app.js
    initNavigation();
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

    // Handle Submenus
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

    // Make sure navData exists before using it
    if (typeof navData !== 'undefined' && navData.auth_id) {
        const signupBtn = document.querySelector('body > nav ul > li:nth-last-of-type(5)');
        const orderHistoryBtn = document.querySelector('body > nav ul > li:nth-last-of-type(4)');
        const dashboardBtn = document.querySelector('body > nav ul > li:nth-last-of-type(3)');
        const logoutBtn = document.querySelector('body > nav ul > li:nth-last-of-type(2)');
        const loginBtn = document.querySelector('body > nav ul > li:last-of-type');
        
        // Check that the elements exist before modifying them
        if (signupBtn) signupBtn.style.display = 'none';
        if (loginBtn) loginBtn.style.display = 'none';
        if (orderHistoryBtn) orderHistoryBtn.style.display = 'block';
        if (dashboardBtn) dashboardBtn.style.display = 'block';
        if (logoutBtn) logoutBtn.style.display = 'block';
    }

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