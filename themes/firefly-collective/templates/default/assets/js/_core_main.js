// template/assets/js/_core_main.js

const appRoot = document.querySelector('#app-root');
const websiteApp = document.querySelector('#website-app');
let isPWA = false;
if (document.querySelector('#pwa-flag')) isPWA = true;

const page = window.location.pathname.split('/')[1];
let themePath;
if (!isPWA) themePath = myApi.themePath;

const logoNameEle = document.querySelector('#logo-name');

function isValidEmail(email) {
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailPattern.test(email);
}

function isValidPhoneNumber(phoneNumber) {
    if (!phoneNumber) return true;
    const phonePattern = /^[0-9\-\+\s\(\)]+$/;
    return phonePattern.test(phoneNumber);
}

document.addEventListener('DOMContentLoaded', function () {
    if ( isPWA || window.location.href.match(/(login|admin)\.php/) ) return;
    if (logoNameEle) logoNameEle.addEventListener('pointerup', ()=>{window.location="/"});
    handleContactSticky();
    function handleContactSticky() {
        if (page === 'contact' || page === 'request-an-appointment' || page === 'dashboard' || page === 'app') return;
        const contactSticky = document.querySelector('#contact-sticky');
    if (contactSticky) contactSticky.addEventListener('pointerup', ()=>{window.location='/request-a-quote';});
        let isVisible = false;
        function toggleContactSticky() {
            if (!isVisible && window.scrollY > 1000 && contactSticky) {
                contactSticky.style.display = 'block';
                isVisible = true;
            }
            if (window.scrollY + window.innerHeight > document.body.offsetHeight - 200 && contactSticky) {
                contactSticky.style.display = 'none';
                isVisible = false;
            }
        }
        window.addEventListener('scroll', toggleContactSticky);
        toggleContactSticky();
    }
});

// Remove empty paragraph tags immediately to prevent layout shift
function removeEmptyParagraphs() {
    const emptyParagraphs = document.querySelectorAll('p');
    emptyParagraphs.forEach(p => {
        // Remove if completely empty or only contains whitespace
        if (!p.textContent.trim() && !p.children.length) {
            p.remove();
        }
    });
}

// Run immediately if DOM is already loaded
if (document.readyState === 'loading') {
    // If still loading, run as soon as DOM is ready (but before images/styles finish)
    document.addEventListener('DOMContentLoaded', removeEmptyParagraphs);
} else {
    // DOM already loaded, run immediately
    removeEmptyParagraphs();
}

// Also run a cleanup after everything loads as a fallback
window.addEventListener('load', removeEmptyParagraphs);
