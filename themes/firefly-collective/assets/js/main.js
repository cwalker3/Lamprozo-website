const appRoot = document.querySelector('#app-root');
const websiteApp = document.querySelector('#website-app');
let isPWA = false;
if (document.querySelector('#pwa-flag')) isPWA = true;

const page = window.location.pathname.split('/')[1];
let themePath;
if (!isPWA) themePath = myApi.themePath;