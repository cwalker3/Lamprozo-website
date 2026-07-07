// pricing.js — entry point. Boots the Vue app over the kept business core.
//
// The original imperative DOM app (PricingApp + ui/*) is replaced by a Vue
// app; the business core (core/*, services/*, utils/*) is reused unchanged
// via store.js. Loaded as an ES module after the Vue global build.

import { createPricingStore } from './store.js';
import { PricingRoot } from './vue/PricingRoot.js';

function boot() {
    try {
        if (typeof window.pricingData === 'undefined' || typeof window.pricingDataSettings === 'undefined') {
            throw new Error('pricing globals not found');
        }
        const mount = document.getElementById('fpa-app');
        if (!mount) {
            throw new Error('#fpa-app mount not found');
        }

        const store = createPricingStore();
        const app = store.Vue.createApp(PricingRoot);
        app.provide('store', store);
        app.mount('#fpa-app');

        if (window.location.search.indexOf('pricingdebug') !== -1) {
            window.pricingStore = store;
            console.log('Pricing store exposed as window.pricingStore');
        }
    } catch (error) {
        console.error('Failed to initialize pricing application:', error);
        const container = document.getElementById('fpa-app');
        if (container) {
            container.innerHTML =
                '<div class="fpa-error"><h3>Pricing System Error</h3><p>' +
                (error && error.message ? error.message : error) +
                '</p><p>Please refresh the page.</p></div>';
        }
    }
}

// Module scripts are deferred, so the DOM is parsed by the time this runs;
// guard against the rare already-fired case anyway.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
