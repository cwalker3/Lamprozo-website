document.addEventListener('DOMContentLoaded', function () {
    const hamburger = document.getElementById('hamburger');
    const closeNavBtn = document.getElementById('close-nav-btn');
    const nav = document.querySelector('body > nav');
    const backdrop = document.getElementById('backdrop');
    const body = document.body;

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

    if (navData.auth_id) {
        const signupBtn = document.querySelector('body > nav ul > li:nth-last-of-type(5)');
        const orderHistoryBtn = document.querySelector('body > nav ul > li:nth-last-of-type(4)');
        const dashboardBtn = document.querySelector('body > nav ul > li:nth-last-of-type(3)');
        const logoutBtn = document.querySelector('body > nav ul > li:nth-last-of-type(2)');
        const loginBtn = document.querySelector('body > nav ul > li:last-of-type');
        signupBtn.style.display = 'none';
        loginBtn.style.display = 'none';
        orderHistoryBtn.style.display = 'block';
        dashboardBtn.style.display = 'block';
        logoutBtn.style.display = 'block';
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
});
