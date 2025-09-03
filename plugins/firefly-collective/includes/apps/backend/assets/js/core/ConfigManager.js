// core/ConfigManager.js

/**
 * Configuration and constants for the pricing system
 */
export class ConfigManager {
    constructor() {
        this.config = {
            // Field types mapping
            FIELD_TYPES: {
                TEXT: 'text',
                TEXTAREA: 'textarea',
                LONG_TEXT: 'long-text',
                NUMBER: 'number',
                INT_FLOAT: 'int-float',
                BOOLEAN: 'boolean',
                DROPDOWN: 'dropdown',
                DATE: 'date',
                ARRAY: 'array'
            },

            // UI constants
            UI: {
                ANIMATION_DURATION: 300,
                DEBOUNCE_DELAY: 300,
                SCROLL_OFFSET: 100,
                MAX_HEIGHT_MULTIPLIER: 1.5,
                CONTAINER_PADDING: 100
            },

            // Default data structures
            DEFAULTS: {
                FEATURE: {
                    featureName: '',
                    description: { text: '' },
                    recurring: false,
                    options: [],
                    link_name: ''
                },

                OPTION: {
                    optionName: '',
                    description: { text: '' },
                    interval: {
                        level: 'admin',
                        ui_type: 'array',
                        value: { types: ['day', 'week', 'month', 'year', 'none'], selected: 4 }
                    },
                    pricingType: {
                        level: 'admin',
                        ui_type: 'array',
                        value: { types: ['static price', 'price range', 'price options'], selected: 0 }
                    },
                    priceFloor: 0,
                    priceCeiling: 0,
                    staticPrice: 0,
                    priceOptions: {
                        level: 'admin',
                        ui_type: 'array-obj',
                        value: { types: [{ label: 'Default', price: 0 }] }
                    },
                    enableThresholdDiscounts: false,
                    thresholdDiscounts: {
                        level: 'admin',
                        ui_type: 'array-obj',
                        value: { types: [{ itemCount: '', discount: '' }] }
                    },
                    maxAddons: -1,
                    optionMetric: '',
                    addons: [],
                    link_name: ''
                },

                ADDON: {
                    addonName: '',
                    description: { text: '' },
                    addOnMetric: '',
                    floorPriceMod: 0,
                    ceilingPriceMod: 0,
                    pricingType: {
                        level: 'admin',
                        ui_type: 'array',
                        value: { types: ['static price', 'price range'], selected: 0 }
                    },
                    priceModifierType: {
                        level: 'admin',
                        ui_type: 'array',
                        value: { types: ['add', 'multiply'], selected: 0 }
                    },
                    staticPriceMod: 0,
                    groupName: '',
                    groupThresholdDiscounts: {
                        level: 'admin',
                        ui_type: 'array-obj',
                        value: { types: [{ itemCount: '', discount: '' }] }
                    },
                    enableGrouping: false,
                    maxGroupItems: -1,
                    link_name: ''
                }
            },

            // Built-in field names that should not have level suffixes
            BUILTIN_FIELDS: [
                // Feature level
                'featureName', 'description', 'recurring',
                'normalText', 'longText', 'intFloat', 'dateField', 'multiple', 'link_name',
                
                // Option level
                'optionName', 'description', 'interval', 'pricingType',
                'staticPrice', 'priceFloor', 'priceCeiling', 'optionMetric', 'priceOptions',
                'thresholdDiscounts', 'link_name',
                
                // Addon level
                'addonName', 'description', 'addOnMetric', 'pricingType', 'priceModifierType',
                'staticPriceMod', 'floorPriceMod', 'ceilingPriceMod', 'groupName',
                'groupThresholdDiscounts', 'link_name'
            ],

            // CSS classes
            CSS_CLASSES: {
                FEATURE: 'feature',
                OPTION: 'option',
                ADDON: 'addon',
                HEADER: 'header',
                CONTENT: 'content',
                CONTENT_INNER: 'content-inner',
                FIELD_GROUP: 'field-group',
                BUTTON_ROW: 'button-row',
                ADD_BUTTON: 'add-button',
                DELETE_BUTTON: 'delete-button',
                CLONE_BUTTON: 'clone-button',
                TOGGLE_INDICATOR: 'toggle-indicator',
                FADE_IN: 'fade-in',
                FADE_OUT: 'fade-out',
                OPEN: 'open',
                NEW_FORM: 'new-feature-form',
                PRICE_OPTIONS_FIELD: 'price-options-field',
                USER_DISPLAY_FIELD: 'user-display-field'
            },

            // Events
            EVENTS: {
                DATA_INITIALIZED: 'dataInitialized',
                DATA_SAVED: 'dataSaved',
                NAME_CHANGED: 'nameChanged',
                FEATURE_ADDED: 'featureAdded',
                FEATURE_DELETED: 'featureDeleted',
                OPTION_ADDED: 'optionAdded',
                OPTION_DELETED: 'optionDeleted',
                ADDON_ADDED: 'addonAdded',
                ADDON_DELETED: 'addonDeleted',
                ELEMENT_EXPANDED: 'elementExpanded',
                ELEMENT_COLLAPSED: 'elementCollapsed',
                FORM_CREATED: 'formCreated',
                FORM_CANCELLED: 'formCancelled',
                CONTAINER_HEIGHT_UPDATE: 'containerHeightUpdate',
                GROUP_SYNC_REQUIRED: 'groupSyncRequired'
            }
        };
    }

    /**
     * Get configuration value
     * @param {string} path - Dot notation path to config value
     * @returns {*} Configuration value
     */
    get(path) {
        return path.split('.').reduce((obj, key) => obj?.[key], this.config);
    }

    /**
     * Set configuration value
     * @param {string} path - Dot notation path
     * @param {*} value - Value to set
     */
    set(path, value) {
        const keys = path.split('.');
        const lastKey = keys.pop();
        const target = keys.reduce((obj, key) => {
            if (!obj[key]) obj[key] = {};
            return obj[key];
        }, this.config);
        target[lastKey] = value;
    }

    /**
     * Get default structure for an entity type
     * @param {string} type - 'feature', 'option', or 'addon'
     * @returns {Object} Default structure
     */
    getDefault(type) {
        const key = type.toUpperCase();
        const defaultData = this.config.DEFAULTS[key];
        return defaultData ? JSON.parse(JSON.stringify(defaultData)) : {};
    }

    /**
     * Check if a field is built-in (should not have level suffix)
     * @param {string} fieldName - Field name to check
     * @returns {boolean} True if built-in
     */
    isBuiltinField(fieldName) {
        return this.config.BUILTIN_FIELDS.includes(fieldName);
    }

    /**
     * Get CSS class name
     * @param {string} className - Class identifier
     * @returns {string} CSS class name
     */
    getClass(className) {
        return this.config.CSS_CLASSES[className.toUpperCase()] || className;
    }

    /**
     * Get event name
     * @param {string} eventName - Event identifier
     * @returns {string} Event name
     */
    getEvent(eventName) {
        return this.config.EVENTS[eventName.toUpperCase()] || eventName;
    }

    /**
     * Get UI configuration value
     * @param {string} key - UI config key
     * @returns {*} UI configuration value
     */
    getUI(key) {
        return this.config.UI[key];
    }

    /**
     * Get field type constant
     * @param {string} type - Field type identifier
     * @returns {string} Field type constant
     */
    getFieldType(type) {
        return this.config.FIELD_TYPES[type.toUpperCase()] || type;
    }

    /**
     * Get all configuration (for debugging)
     * @returns {Object} Complete configuration object
     */
    getAll() {
        return JSON.parse(JSON.stringify(this.config));
    }
}