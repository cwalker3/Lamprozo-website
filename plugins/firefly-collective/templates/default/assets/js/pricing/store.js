// store.js — wires the KEPT business core to Vue reactivity.
//
// The custom architecture is preserved verbatim: EventBus, DataManager,
// ConfigManager, ApiService, GroupSyncService, ValidationService are the
// same modules the original app used. The only change is the integration
// seam — after DataManager loads its data we replace its plain `data`
// object with a Vue reactive() proxy of itself. Because it's the SAME
// underlying object, every mutation (DataManager's own addFeature/
// deleteOption/etc. AND Vue v-model edits AND GroupSyncService's group
// sync, which all read through dataManager) flows through one reactive
// source of truth, so Vue re-renders automatically. The EventBus still
// fires but the UI no longer depends on it to render.

import { EventBus } from './core/EventBus.js';
import { DataManager } from './core/DataManager.js';
import { ConfigManager } from './core/ConfigManager.js';
import { ApiService } from './services/ApiService.js';
import { GroupSyncService } from './services/GroupSyncService.js';
import { ValidationService } from './services/ValidationService.js';

export function createPricingStore() {
    const Vue = window.Vue;
    if (!Vue) {
        throw new Error('Vue global not found (vue-js must load before pricing.js)');
    }

    // Read base data from the localized global BEFORE DataManager.initialize()
    // overwrites window.pricingData with its own data reference.
    const baseData =
        window.pricingData && window.pricingData.data && Array.isArray(window.pricingData.data.features)
            ? { features: window.pricingData.data.features }
            : { features: [] };

    const settings = window.pricingDataSettings || {};

    // Instantiate the kept core (same wiring PricingApp used).
    const eventBus  = new EventBus();
    const config    = new ConfigManager();
    const dataManager = new DataManager(eventBus);
    const api       = new ApiService(eventBus, config);
    const groupSync = new GroupSyncService(eventBus, dataManager);
    const validation = new ValidationService(eventBus, config);

    dataManager.initialize(baseData);
    api.initialize({ nonce: settings.nonce, apiUrl: settings.apiUrl });

    // ── THE SEAM ──────────────────────────────────────────────────────────
    dataManager.data = Vue.reactive(dataManager.data);
    // Keep the legacy global pointing at the (now reactive) data.
    window.pricingData = dataManager.data;

    return { Vue, eventBus, config, dataManager, api, groupSync, validation };
}
