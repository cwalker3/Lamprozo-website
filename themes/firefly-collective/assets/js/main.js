// theme/assets/js/main.js

const appRoot = document.querySelector('#app-root');
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
    logoNameEle.addEventListener('pointerup', ()=>{window.location="/"});
    handleContactSticky();
    function handleContactSticky() {
        if (page === 'contact' || page === 'request-an-appointment' || page === 'dashboard' || page === 'app') return;
        const contactSticky = document.querySelector('#contact-sticky');
        contactSticky.addEventListener('pointerup', ()=>{window.location='/request-a-quote';});
        let isVisible = false;
        function toggleContactSticky() {
            if (!isVisible && window.scrollY > 1000) {
                contactSticky.style.display = 'block';
                isVisible = true;
            }
            if (window.scrollY + window.innerHeight > document.body.offsetHeight - 200) {
                contactSticky.style.display = 'none';
                isVisible = false;
            }
        }
        window.addEventListener('scroll', toggleContactSticky);
        toggleContactSticky();
    }
});
