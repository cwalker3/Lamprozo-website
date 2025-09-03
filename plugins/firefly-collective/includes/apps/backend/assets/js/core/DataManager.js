// core/DataManager.js

/**
 * Centralized data management for pricing system
 */
export class DataManager {
    constructor(eventBus) {
        this.eventBus = eventBus;
        this.data = { features: [] };
        this.nameChanges = { features: {}, options: {}, addons: {} };
        this.initialized = false;
    }

    /**
     * Initialize data manager with base data
     * @param {Object} baseData - Initial data from server
     */
    initialize(baseData) {
        if (this.initialized) {
            console.warn('DataManager already initialized');
            return;
        }

        // Load name changes from session storage
        this.nameChanges = this.loadNameChanges();
        
        // Merge base data with session storage
        const sessionData = this.loadSessionData();
        this.data = sessionData ? this.mergeData(baseData, sessionData) : baseData;
        
        // Normalize descriptions
        this.normalizeDescriptions();
        
        this.initialized = true;
        this.eventBus.emit('dataInitialized', this.data);
    }

    /**
     * Get current data
     * @returns {Object} Current pricing data
     */
    getData() {
        return this.data;
    }

    /**
     * Get name changes
     * @returns {Object} Current name changes
     */
    getNameChanges() {
        return this.nameChanges;
    }

    /**
     * Save current state to session storage
     */
    save() {
        this.saveSessionData();
        this.saveNameChanges();
        this.eventBus.emit('dataSaved', this.data);
    }

    /**
     * Record a name change
     * @param {string} type - Type: 'features', 'options', 'addons'
     * @param {Array} indices - Array of indices
     * @param {string} oldName - Previous name
     * @param {string} newName - New name
     */
    recordNameChange(type, indices, oldName, newName) {
        let container = this.nameChanges[type];
        
        // Navigate to the correct nested position
        for (let i = 0; i < indices.length - 1; i++) {
            const key = indices[i];
            if (!container[key]) container[key] = {};
            container = container[key];
        }
        
        const lastIndex = indices[indices.length - 1];
        const firstOld = container[lastIndex]?.oldName || oldName;
        container[lastIndex] = { oldName: firstOld, newName };
        
        this.eventBus.emit('nameChanged', { type, indices, oldName, newName });
    }

    /**
     * Add a new feature
     * @param {Object} feature - Feature data
     * @returns {number} Index of added feature
     */
    addFeature(feature) {
        const index = this.data.features.push(feature) - 1;
        this.save();
        this.eventBus.emit('featureAdded', { feature, index });
        return index;
    }

    /**
     * Delete a feature
     * @param {number} index - Feature index
     */
    deleteFeature(index) {
        if (index >= 0 && index < this.data.features.length) {
            const feature = this.data.features[index];
            this.data.features.splice(index, 1);
            this.save();
            this.eventBus.emit('featureDeleted', { feature, index });
        }
    }

    /**
     * Add option to feature
     * @param {number} featureIndex - Feature index
     * @param {Object} option - Option data
     * @returns {number} Index of added option
     */
    addOption(featureIndex, option) {
        if (!this.data.features[featureIndex]) return -1;
        
        const index = this.data.features[featureIndex].options.push(option) - 1;
        this.save();
        this.eventBus.emit('optionAdded', { option, featureIndex, index });
        return index;
    }

    /**
     * Delete option from feature
     * @param {number} featureIndex - Feature index
     * @param {number} optionIndex - Option index
     */
    deleteOption(featureIndex, optionIndex) {
        const feature = this.data.features[featureIndex];
        if (!feature || !feature.options[optionIndex]) return;
        
        const option = feature.options[optionIndex];
        feature.options.splice(optionIndex, 1);
        this.save();
        this.eventBus.emit('optionDeleted', { option, featureIndex, optionIndex });
    }

    /**
     * Add addon to option
     * @param {number} featureIndex - Feature index
     * @param {number} optionIndex - Option index
     * @param {Object} addon - Addon data
     * @returns {number} Index of added addon
     */
    addAddon(featureIndex, optionIndex, addon) {
        const option = this.data.features[featureIndex]?.options[optionIndex];
        if (!option) return -1;
        
        const index = option.addons.push(addon) - 1;
        this.save();
        this.eventBus.emit('addonAdded', { addon, featureIndex, optionIndex, index });
        return index;
    }

    /**
     * Delete addon from option
     * @param {number} featureIndex - Feature index
     * @param {number} optionIndex - Option index
     * @param {number} addonIndex - Addon index
     */
    deleteAddon(featureIndex, optionIndex, addonIndex) {
        const addon = this.data.features[featureIndex]?.options[optionIndex]?.addons[addonIndex];
        if (!addon) return;
        
        this.data.features[featureIndex].options[optionIndex].addons.splice(addonIndex, 1);
        this.save();
        this.eventBus.emit('addonDeleted', { addon, featureIndex, optionIndex, addonIndex });
    }

    /**
     * Get available metrics
     * @returns {Object} Object with optionMetrics and addonMetrics arrays
     */
    getAvailableMetrics() {
        const optionMetrics = [];
        const addonMetrics = [];

        this.data.features.forEach(feature => {
            feature.options.forEach(option => {
                if (option.optionMetric && !optionMetrics.includes(option.optionMetric)) {
                    optionMetrics.push(option.optionMetric);
                }

                option.addons.forEach(addon => {
                    if (addon.addOnMetric && !addonMetrics.includes(addon.addOnMetric)) {
                        addonMetrics.push(addon.addOnMetric);
                    }
                });
            });
        });

        return { optionMetrics, addonMetrics };
    }

    /**
     * Load data from session storage
     * @returns {Object|null} Stored data or null
     */
    loadSessionData() {
        try {
            const stored = sessionStorage.getItem('pricingData');
            return stored ? JSON.parse(stored) : null;
        } catch (error) {
            console.error('Error loading session data:', error);
            return null;
        }
    }

    /**
     * Save data to session storage
     */
    saveSessionData() {
        try {
            sessionStorage.setItem('pricingData', JSON.stringify(this.data));
        } catch (error) {
            console.error('Error saving session data:', error);
        }
    }

    /**
     * Load name changes from session storage
     * @returns {Object} Name changes object
     */
    loadNameChanges() {
        try {
            const stored = sessionStorage.getItem('nameChanges');
            return stored ? JSON.parse(stored) : { features: {}, options: {}, addons: {} };
        } catch (error) {
            console.error('Error loading name changes:', error);
            return { features: {}, options: {}, addons: {} };
        }
    }

    /**
     * Save name changes to session storage
     */
    saveNameChanges() {
        try {
            sessionStorage.setItem('nameChanges', JSON.stringify(this.nameChanges));
        } catch (error) {
            console.error('Error saving name changes:', error);
        }
    }

    /**
     * Merge session data with base data
     * @param {Object} base - Base data
     * @param {Object} overlay - Session data to overlay
     * @returns {Object} Merged data
     */
    mergeData(base, overlay) {
        if (base && typeof base === 'object' && 'ui_type' in base && 'value' in base) {
            const overlayValue = overlay && overlay.value !== undefined ? overlay.value : overlay;
            return {
                level: base.level,
                ui_type: base.ui_type,
                value: this.mergeData(base.value, overlayValue)
            };
        }

        if (Array.isArray(base) && Array.isArray(overlay)) {
            const merged = base.map((item, index) =>
                index in overlay ? this.mergeData(item, overlay[index]) : item
            );
            if (overlay.length > base.length) {
                merged.push(...overlay.slice(base.length));
            }
            return merged;
        }

        if (base && typeof base === 'object' && !Array.isArray(base) &&
            overlay && typeof overlay === 'object' && !Array.isArray(overlay)) {
            const merged = {};
            for (const key in base) {
                if (!base.hasOwnProperty(key)) continue;
                merged[key] = key in overlay ? this.mergeData(base[key], overlay[key]) : base[key];
            }
            return merged;
        }

        return overlay !== undefined ? overlay : base;
    }

    /**
     * Normalize description objects to ensure consistent structure
     */
    normalizeDescriptions() {
        this.data.features.forEach(feature => {
            if (!feature.description || typeof feature.description.text !== 'string') {
                feature.description = { text: '' };
            }

            feature.options.forEach(option => {
                if (!option.description || typeof option.description.text !== 'string') {
                    option.description = { text: '' };
                }

                option.addons.forEach(addon => {
                    if (!addon.description || typeof addon.description.text !== 'string') {
                        addon.description = { text: '' };
                    }
                });
            });
        });
    }

    /**
     * Clear all name changes (typically after successful save)
     */
    clearNameChanges() {
        this.nameChanges = { features: {}, options: {}, addons: {} };
        this.saveNameChanges();
        this.eventBus.emit('nameChangesCleared');
    }
}